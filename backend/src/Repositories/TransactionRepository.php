<?php

declare(strict_types=1);

namespace App\Repositories;

use PDO;

/**
 * The only class that knows how transactions are stored.
 *
 * Filtering, pagination and the aggregate queries the dashboard and budgets
 * need all live here so the services stay free of SQL. Every method takes a
 * user id and every WHERE clause includes it -- scoping is not something a
 * caller can forget to ask for.
 */
final class TransactionRepository
{
    private const SELECT = 'SELECT t.*,
            a.name AS account_name,
            ta.name AS transfer_to_account_name,
            c.name AS category_name,
            c.icon AS category_icon,
            c.color AS category_color,
            sc.name AS subcategory_name
        FROM transactions t
        JOIN accounts a ON a.id = t.account_id
        LEFT JOIN accounts ta ON ta.id = t.transfer_to_account_id
        LEFT JOIN categories c ON c.id = t.category_id
        LEFT JOIN subcategories sc ON sc.id = t.subcategory_id';

    public function __construct(private readonly PDO $pdo)
    {
    }

    /**
     * @param array<string, mixed> $filters
     * @return array{rows: array<int, array<string, mixed>>, total: int}
     */
    public function paginate(int $userId, array $filters, int $page, int $perPage): array
    {
        [$where, $params] = $this->buildFilters($userId, $filters);

        $countStmt = $this->pdo->prepare("SELECT COUNT(*) FROM transactions t WHERE {$where}");
        $countStmt->execute($params);
        $total = (int) $countStmt->fetchColumn();

        $offset = max(0, ($page - 1) * $perPage);
        $stmt = $this->pdo->prepare(
            self::SELECT . " WHERE {$where} ORDER BY t.transaction_date DESC, t.id DESC LIMIT {$perPage} OFFSET {$offset}"
        );
        $stmt->execute($params);

        return ['rows' => $stmt->fetchAll(), 'total' => $total];
    }

    /**
     * Every matching row, unpaginated -- used by the CSV export.
     *
     * @param array<string, mixed> $filters
     * @return array<int, array<string, mixed>>
     */
    public function all(int $userId, array $filters): array
    {
        [$where, $params] = $this->buildFilters($userId, $filters);

        $stmt = $this->pdo->prepare(
            self::SELECT . " WHERE {$where} ORDER BY t.transaction_date ASC, t.id ASC"
        );
        $stmt->execute($params);

        return $stmt->fetchAll();
    }

    /** @return array<string, mixed>|null */
    public function findForUser(int $id, int $userId): ?array
    {
        $stmt = $this->pdo->prepare(self::SELECT . ' WHERE t.id = :id AND t.user_id = :user_id');
        $stmt->execute(['id' => $id, 'user_id' => $userId]);
        $row = $stmt->fetch();

        return $row === false ? null : $row;
    }

    /** @param array<string, mixed> $data */
    public function create(int $userId, array $data): array
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO transactions
                (user_id, account_id, category_id, subcategory_id, type, amount, transfer_to_account_id,
                 description, notes, tags, transaction_date)
             VALUES
                (:user_id, :account_id, :category_id, :subcategory_id, :type, :amount, :transfer_to_account_id,
                 :description, :notes, :tags, :transaction_date)'
        );
        $stmt->execute([
            'user_id' => $userId,
            'account_id' => $data['account_id'],
            'category_id' => $data['category_id'],
            'subcategory_id' => $data['subcategory_id'],
            'type' => $data['type'],
            'amount' => $data['amount'],
            'transfer_to_account_id' => $data['transfer_to_account_id'],
            'description' => $data['description'],
            'notes' => $data['notes'],
            'tags' => $data['tags'],
            'transaction_date' => $data['transaction_date'],
        ]);

        /** @var array<string, mixed> */
        return $this->findForUser((int) $this->pdo->lastInsertId(), $userId);
    }

    /** @param array<string, mixed> $data */
    public function update(int $id, int $userId, array $data): ?array
    {
        $stmt = $this->pdo->prepare(
            "UPDATE transactions SET
                account_id = :account_id,
                category_id = :category_id,
                subcategory_id = :subcategory_id,
                type = :type,
                amount = :amount,
                transfer_to_account_id = :transfer_to_account_id,
                description = :description,
                notes = :notes,
                tags = :tags,
                transaction_date = :transaction_date,
                updated_at = datetime('now')
             WHERE id = :id AND user_id = :user_id"
        );
        $stmt->execute([
            'account_id' => $data['account_id'],
            'category_id' => $data['category_id'],
            'subcategory_id' => $data['subcategory_id'],
            'type' => $data['type'],
            'amount' => $data['amount'],
            'transfer_to_account_id' => $data['transfer_to_account_id'],
            'description' => $data['description'],
            'notes' => $data['notes'],
            'tags' => $data['tags'],
            'transaction_date' => $data['transaction_date'],
            'id' => $id,
            'user_id' => $userId,
        ]);

        return $this->findForUser($id, $userId);
    }

    public function delete(int $id, int $userId): bool
    {
        $stmt = $this->pdo->prepare('DELETE FROM transactions WHERE id = :id AND user_id = :user_id');
        $stmt->execute(['id' => $id, 'user_id' => $userId]);

        return $stmt->rowCount() > 0;
    }

    /**
     * Income and expense totals (in cents) for a date range. Transfers are
     * excluded on purpose: moving your own money between accounts is not
     * income and not spending.
     *
     * @return array{income: int, expense: int}
     */
    public function totalsBetween(int $userId, string $from, string $to): array
    {
        $stmt = $this->pdo->prepare(
            "SELECT
                COALESCE(SUM(CASE WHEN type = 'income' THEN amount END), 0) AS income,
                COALESCE(SUM(CASE WHEN type = 'expense' THEN amount END), 0) AS expense
             FROM transactions
             WHERE user_id = :user_id AND transaction_date BETWEEN :from AND :to"
        );
        $stmt->execute(['user_id' => $userId, 'from' => $from, 'to' => $to]);
        $row = $stmt->fetch() ?: ['income' => 0, 'expense' => 0];

        return ['income' => (int) $row['income'], 'expense' => (int) $row['expense']];
    }

    /**
     * Per-category totals for a date range, biggest first.
     *
     * @return array<int, array<string, mixed>>
     */
    public function categoryTotals(int $userId, string $from, string $to, string $type = 'expense'): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT
                t.category_id,
                COALESCE(c.name, :uncategorised) AS category_name,
                COALESCE(c.icon, :no_icon) AS category_icon,
                COALESCE(c.color, :fallback_color) AS category_color,
                SUM(t.amount) AS total,
                COUNT(*) AS transaction_count
             FROM transactions t
             LEFT JOIN categories c ON c.id = t.category_id
             WHERE t.user_id = :user_id AND t.type = :type
               AND t.transaction_date BETWEEN :from AND :to
             GROUP BY t.category_id
             ORDER BY total DESC'
        );
        $stmt->execute([
            'user_id' => $userId,
            'type' => $type,
            'from' => $from,
            'to' => $to,
            'uncategorised' => 'Uncategorised',
            'no_icon' => '',
            'fallback_color' => '#9e9e9e',
        ]);

        return $stmt->fetchAll();
    }

    /**
     * Monthly income/expense totals for the given inclusive month range.
     *
     * @return array<string, array{income: int, expense: int}> keyed by 'YYYY-MM'
     */
    public function monthlyTotals(int $userId, string $fromMonth, string $toMonth): array
    {
        $stmt = $this->pdo->prepare(
            "SELECT
                substr(transaction_date, 1, 7) AS month,
                COALESCE(SUM(CASE WHEN type = 'income' THEN amount END), 0) AS income,
                COALESCE(SUM(CASE WHEN type = 'expense' THEN amount END), 0) AS expense
             FROM transactions
             WHERE user_id = :user_id
               AND substr(transaction_date, 1, 7) BETWEEN :from AND :to
             GROUP BY month
             ORDER BY month ASC"
        );
        $stmt->execute(['user_id' => $userId, 'from' => $fromMonth, 'to' => $toMonth]);

        $totals = [];

        foreach ($stmt->fetchAll() as $row) {
            $totals[$row['month']] = ['income' => (int) $row['income'], 'expense' => (int) $row['expense']];
        }

        return $totals;
    }

    /** Total spent (cents) in one category during one 'YYYY-MM' month. */
    public function spentInCategoryForMonth(int $userId, int $categoryId, string $month): int
    {
        $stmt = $this->pdo->prepare(
            "SELECT COALESCE(SUM(amount), 0)
             FROM transactions
             WHERE user_id = :user_id
               AND category_id = :category_id
               AND type = 'expense'
               AND substr(transaction_date, 1, 7) = :month"
        );
        $stmt->execute(['user_id' => $userId, 'category_id' => $categoryId, 'month' => $month]);

        return (int) $stmt->fetchColumn();
    }

    /**
     * Spend per category for a month in one query, so a page of budgets does
     * not fan out into one query per budget.
     *
     * @return array<int, int> category id => cents spent
     */
    public function spentByCategoryForMonth(int $userId, string $month): array
    {
        $stmt = $this->pdo->prepare(
            "SELECT category_id, SUM(amount) AS total
             FROM transactions
             WHERE user_id = :user_id AND type = 'expense'
               AND category_id IS NOT NULL
               AND substr(transaction_date, 1, 7) = :month
             GROUP BY category_id"
        );
        $stmt->execute(['user_id' => $userId, 'month' => $month]);

        $spend = [];

        foreach ($stmt->fetchAll() as $row) {
            $spend[(int) $row['category_id']] = (int) $row['total'];
        }

        return $spend;
    }

    /**
     * Builds the shared WHERE clause for the list/export/count queries.
     *
     * @param array<string, mixed> $filters
     * @return array{0: string, 1: array<string, mixed>}
     */
    private function buildFilters(int $userId, array $filters): array
    {
        $where = ['t.user_id = :user_id'];
        $params = ['user_id' => $userId];

        if (!empty($filters['account_id'])) {
            // A transfer belongs to both of its accounts.
            $where[] = '(t.account_id = :account_id OR t.transfer_to_account_id = :account_id)';
            $params['account_id'] = (int) $filters['account_id'];
        }

        if (!empty($filters['category_id'])) {
            $where[] = 't.category_id = :category_id';
            $params['category_id'] = (int) $filters['category_id'];
        }

        if (!empty($filters['subcategory_id'])) {
            $where[] = 't.subcategory_id = :subcategory_id';
            $params['subcategory_id'] = (int) $filters['subcategory_id'];
        }

        if (!empty($filters['type'])) {
            $where[] = 't.type = :type';
            $params['type'] = $filters['type'];
        }

        if (!empty($filters['date_from'])) {
            $where[] = 't.transaction_date >= :date_from';
            $params['date_from'] = $filters['date_from'];
        }

        if (!empty($filters['date_to'])) {
            $where[] = 't.transaction_date <= :date_to';
            $params['date_to'] = $filters['date_to'];
        }

        if (!empty($filters['search'])) {
            $where[] = "(t.description LIKE :search OR COALESCE(t.notes, '') LIKE :search)";
            $params['search'] = '%' . $filters['search'] . '%';
        }

        return [implode(' AND ', $where), $params];
    }
}
