<?php

declare(strict_types=1);

namespace App\Repositories;

use PDO;

/**
 * Subcategories are all system rows today -- seeded by migration 0011, shared
 * by every user, no ownership column to filter on. That keeps this repository
 * a plain read: `visibleTo()` on categories has no counterpart here yet.
 */
final class SubcategoryRepository
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    /** @return array<int, array<string, mixed>> */
    public function all(?int $categoryId = null): array
    {
        $sql = 'SELECT * FROM subcategories';
        $params = [];

        if ($categoryId !== null) {
            $sql .= ' WHERE category_id = :category_id';
            $params['category_id'] = $categoryId;
        }

        $sql .= ' ORDER BY category_id ASC, name COLLATE NOCASE ASC';

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);

        return $stmt->fetchAll();
    }

    /** @return array<string, mixed>|null */
    public function find(int $id): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM subcategories WHERE id = :id');
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch();

        return $row === false ? null : $row;
    }
}
