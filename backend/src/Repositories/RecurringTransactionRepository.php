<?php

declare(strict_types=1);

namespace App\Repositories;

use PDO;

final class RecurringTransactionRepository
{
    private const SELECT = 'SELECT r.*, a.name AS account_name, a.archived AS account_archived,
            c.name AS category_name, c.icon AS category_icon, c.color AS category_color
        FROM recurring_transactions r
        JOIN accounts a ON a.id = r.account_id
        LEFT JOIN categories c ON c.id = r.category_id';

    public function __construct(private readonly PDO $pdo)
    {
    }

    /** @return array<int, array<string, mixed>> */
    public function allForUser(int $userId): array
    {
        $stmt = $this->pdo->prepare(
            self::SELECT . ' WHERE r.user_id = :user_id ORDER BY r.active DESC, r.next_run_date ASC'
        );
        $stmt->execute(['user_id' => $userId]);

        return $stmt->fetchAll();
    }

    /** @return array<string, mixed>|null */
    public function findForUser(int $id, int $userId): ?array
    {
        $stmt = $this->pdo->prepare(self::SELECT . ' WHERE r.id = :id AND r.user_id = :user_id');
        $stmt->execute(['id' => $id, 'user_id' => $userId]);
        $row = $stmt->fetch();

        return $row === false ? null : $row;
    }

    /**
     * Active schedules whose next run has arrived.
     *
     * @return array<int, array<string, mixed>>
     */
    public function due(int $userId, string $onOrBefore): array
    {
        $stmt = $this->pdo->prepare(
            self::SELECT . ' WHERE r.user_id = :user_id AND r.active = 1 AND r.next_run_date <= :date
             ORDER BY r.next_run_date ASC'
        );
        $stmt->execute(['user_id' => $userId, 'date' => $onOrBefore]);

        return $stmt->fetchAll();
    }

    /** Cheap pre-check so the auto-run hook does not fan out on every request. */
    public function hasDue(int $userId, string $onOrBefore): bool
    {
        $stmt = $this->pdo->prepare(
            'SELECT 1 FROM recurring_transactions
             WHERE user_id = :user_id AND active = 1 AND next_run_date <= :date LIMIT 1'
        );
        $stmt->execute(['user_id' => $userId, 'date' => $onOrBefore]);

        return $stmt->fetchColumn() !== false;
    }

    /** @param array<string, mixed> $data */
    public function create(int $userId, array $data): array
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO recurring_transactions
                (user_id, account_id, category_id, type, amount, description, frequency, next_run_date, active)
             VALUES
                (:user_id, :account_id, :category_id, :type, :amount, :description, :frequency, :next_run_date, :active)'
        );
        $stmt->execute([
            'user_id' => $userId,
            'account_id' => $data['account_id'],
            'category_id' => $data['category_id'],
            'type' => $data['type'],
            'amount' => $data['amount'],
            'description' => $data['description'],
            'frequency' => $data['frequency'],
            'next_run_date' => $data['next_run_date'],
            'active' => $data['active'],
        ]);

        /** @var array<string, mixed> */
        return $this->findForUser((int) $this->pdo->lastInsertId(), $userId);
    }

    /** @param array<string, mixed> $fields */
    public function update(int $id, int $userId, array $fields): ?array
    {
        if ($fields === []) {
            return $this->findForUser($id, $userId);
        }

        $assignments = implode(', ', array_map(static fn (string $c): string => "{$c} = :{$c}", array_keys($fields)));
        $stmt = $this->pdo->prepare(
            "UPDATE recurring_transactions SET {$assignments} WHERE id = :id AND user_id = :user_id"
        );
        $stmt->execute($fields + ['id' => $id, 'user_id' => $userId]);

        return $this->findForUser($id, $userId);
    }

    public function delete(int $id, int $userId): bool
    {
        $stmt = $this->pdo->prepare('DELETE FROM recurring_transactions WHERE id = :id AND user_id = :user_id');
        $stmt->execute(['id' => $id, 'user_id' => $userId]);

        return $stmt->rowCount() > 0;
    }
}
