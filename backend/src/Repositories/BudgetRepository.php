<?php

declare(strict_types=1);

namespace App\Repositories;

use PDO;

final class BudgetRepository
{
    private const SELECT = 'SELECT b.*, c.name AS category_name, c.icon AS category_icon, c.color AS category_color
        FROM budgets b
        JOIN categories c ON c.id = b.category_id';

    public function __construct(private readonly PDO $pdo)
    {
    }

    /** @return array<int, array<string, mixed>> */
    public function forMonth(int $userId, string $month): array
    {
        $stmt = $this->pdo->prepare(
            self::SELECT . ' WHERE b.user_id = :user_id AND b.month = :month
             ORDER BY c.name COLLATE NOCASE ASC'
        );
        $stmt->execute(['user_id' => $userId, 'month' => $month]);

        return $stmt->fetchAll();
    }

    /** @return array<string, mixed>|null */
    public function findForUser(int $id, int $userId): ?array
    {
        $stmt = $this->pdo->prepare(self::SELECT . ' WHERE b.id = :id AND b.user_id = :user_id');
        $stmt->execute(['id' => $id, 'user_id' => $userId]);
        $row = $stmt->fetch();

        return $row === false ? null : $row;
    }

    /** @return array<string, mixed>|null */
    public function findByCategoryAndMonth(int $userId, int $categoryId, string $month, ?int $exceptId = null): ?array
    {
        $sql = 'SELECT * FROM budgets WHERE user_id = :user_id AND category_id = :category_id AND month = :month';
        $params = ['user_id' => $userId, 'category_id' => $categoryId, 'month' => $month];

        if ($exceptId !== null) {
            $sql .= ' AND id != :except';
            $params['except'] = $exceptId;
        }

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        $row = $stmt->fetch();

        return $row === false ? null : $row;
    }

    public function create(int $userId, int $categoryId, string $month, int $limit): array
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO budgets (user_id, category_id, month, amount_limit)
             VALUES (:user_id, :category_id, :month, :amount_limit)'
        );
        $stmt->execute([
            'user_id' => $userId,
            'category_id' => $categoryId,
            'month' => $month,
            'amount_limit' => $limit,
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
        $stmt = $this->pdo->prepare("UPDATE budgets SET {$assignments} WHERE id = :id AND user_id = :user_id");
        $stmt->execute($fields + ['id' => $id, 'user_id' => $userId]);

        return $this->findForUser($id, $userId);
    }

    public function delete(int $id, int $userId): bool
    {
        $stmt = $this->pdo->prepare('DELETE FROM budgets WHERE id = :id AND user_id = :user_id');
        $stmt->execute(['id' => $id, 'user_id' => $userId]);

        return $stmt->rowCount() > 0;
    }
}
