<?php

declare(strict_types=1);

namespace App\Repositories;

use PDO;

/**
 * Categories visible to a user are the system defaults (user_id IS NULL,
 * seeded by migration 0004 and shared by everyone) plus that user's own rows.
 */
final class CategoryRepository
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    /** @return array<int, array<string, mixed>> */
    public function visibleTo(int $userId, ?string $type = null): array
    {
        $sql = 'SELECT * FROM categories WHERE (user_id IS NULL OR user_id = :user_id)';
        $params = ['user_id' => $userId];

        if ($type !== null) {
            $sql .= ' AND type = :type';
            $params['type'] = $type;
        }

        $sql .= ' ORDER BY type ASC, (user_id IS NULL) DESC, name COLLATE NOCASE ASC';

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);

        return $stmt->fetchAll();
    }

    /** @return array<string, mixed>|null */
    public function findVisible(int $id, int $userId): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM categories WHERE id = :id AND (user_id IS NULL OR user_id = :user_id)'
        );
        $stmt->execute(['id' => $id, 'user_id' => $userId]);
        $row = $stmt->fetch();

        return $row === false ? null : $row;
    }

    public function findOwnedByName(int $userId, string $name, string $type, ?int $exceptId = null): ?array
    {
        $sql = 'SELECT * FROM categories
                WHERE user_id = :user_id AND name = :name COLLATE NOCASE AND type = :type';
        $params = ['user_id' => $userId, 'name' => $name, 'type' => $type];

        if ($exceptId !== null) {
            $sql .= ' AND id != :except';
            $params['except'] = $exceptId;
        }

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        $row = $stmt->fetch();

        return $row === false ? null : $row;
    }

    /** @param array{name: string, type: string, icon: string, color: string} $data */
    public function create(int $userId, array $data): array
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO categories (user_id, name, type, icon, color)
             VALUES (:user_id, :name, :type, :icon, :color)'
        );
        $stmt->execute([
            'user_id' => $userId,
            'name' => $data['name'],
            'type' => $data['type'],
            'icon' => $data['icon'],
            'color' => $data['color'],
        ]);

        /** @var array<string, mixed> */
        return $this->findVisible((int) $this->pdo->lastInsertId(), $userId);
    }

    /** @param array<string, mixed> $fields */
    public function update(int $id, int $userId, array $fields): ?array
    {
        if ($fields === []) {
            return $this->findVisible($id, $userId);
        }

        $assignments = implode(', ', array_map(static fn (string $c): string => "{$c} = :{$c}", array_keys($fields)));
        $stmt = $this->pdo->prepare("UPDATE categories SET {$assignments} WHERE id = :id AND user_id = :user_id");
        $stmt->execute($fields + ['id' => $id, 'user_id' => $userId]);

        return $this->findVisible($id, $userId);
    }

    public function delete(int $id, int $userId): bool
    {
        $stmt = $this->pdo->prepare('DELETE FROM categories WHERE id = :id AND user_id = :user_id');
        $stmt->execute(['id' => $id, 'user_id' => $userId]);

        return $stmt->rowCount() > 0;
    }
}
