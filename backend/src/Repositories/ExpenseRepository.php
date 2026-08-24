<?php

declare(strict_types=1);

namespace App\Repositories;

use PDO;

final class ExpenseRepository
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    /**
     * @param array{category?: string, from?: string, to?: string} $filters
     * @return array<int, array<string, mixed>>
     */
    public function allForUser(int $userId, array $filters = []): array
    {
        $sql = 'SELECT * FROM expenses WHERE user_id = :user_id';
        $params = ['user_id' => $userId];

        if (!empty($filters['category'])) {
            $sql .= ' AND category = :category';
            $params['category'] = $filters['category'];
        }

        if (!empty($filters['from'])) {
            $sql .= ' AND expense_date >= :from';
            $params['from'] = $filters['from'];
        }

        if (!empty($filters['to'])) {
            $sql .= ' AND expense_date <= :to';
            $params['to'] = $filters['to'];
        }

        $sql .= ' ORDER BY expense_date DESC, id DESC';

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);

        return $stmt->fetchAll();
    }

    public function find(int $id, int $userId): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM expenses WHERE id = :id AND user_id = :user_id');
        $stmt->execute(['id' => $id, 'user_id' => $userId]);
        $expense = $stmt->fetch();

        return $expense === false ? null : $expense;
    }

    /**
     * @param array{amount: float, category: string, description: ?string, expense_date: string} $data
     */
    public function create(int $userId, array $data): array
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO expenses (user_id, amount, category, description, expense_date)
             VALUES (:user_id, :amount, :category, :description, :expense_date)'
        );
        $stmt->execute([
            'user_id' => $userId,
            'amount' => $data['amount'],
            'category' => $data['category'],
            'description' => $data['description'] ?? null,
            'expense_date' => $data['expense_date'],
        ]);

        return $this->find((int) $this->pdo->lastInsertId(), $userId);
    }

    /**
     * @param array<string, mixed> $data Partial set of fields to update.
     */
    public function update(int $id, int $userId, array $data): ?array
    {
        if ($this->find($id, $userId) === null) {
            return null;
        }

        $fields = [];
        $params = ['id' => $id, 'user_id' => $userId];

        foreach (['amount', 'category', 'description', 'expense_date'] as $field) {
            if (array_key_exists($field, $data)) {
                $fields[] = "{$field} = :{$field}";
                $params[$field] = $data[$field];
            }
        }

        if ($fields !== []) {
            $fields[] = 'updated_at = CURRENT_TIMESTAMP';
            $sql = 'UPDATE expenses SET ' . implode(', ', $fields) . ' WHERE id = :id AND user_id = :user_id';
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute($params);
        }

        return $this->find($id, $userId);
    }

    public function delete(int $id, int $userId): bool
    {
        $stmt = $this->pdo->prepare('DELETE FROM expenses WHERE id = :id AND user_id = :user_id');
        $stmt->execute(['id' => $id, 'user_id' => $userId]);

        return $stmt->rowCount() > 0;
    }

    /**
     * @return array<int, array{category: string, total: float, count: int}>
     */
    public function summaryByCategory(int $userId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT category, SUM(amount) AS total, COUNT(*) AS count
             FROM expenses
             WHERE user_id = :user_id
             GROUP BY category
             ORDER BY total DESC'
        );
        $stmt->execute(['user_id' => $userId]);

        return array_map(
            static fn (array $row) => [
                'category' => $row['category'],
                'total' => round((float) $row['total'], 2),
                'count' => (int) $row['count'],
            ],
            $stmt->fetchAll()
        );
    }
}
