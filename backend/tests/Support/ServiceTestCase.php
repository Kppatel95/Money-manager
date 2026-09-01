<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Database;
use PDO;
use PHPUnit\Framework\TestCase;

/**
 * Base class for service tests that exercise real SQL against a throwaway
 * SQLite file. Repositories are thin enough that mocking them would mostly
 * test the mocks; running the real queries catches the things that actually
 * break (joins, filters, aggregate arithmetic).
 */
abstract class ServiceTestCase extends TestCase
{
    protected string $dbPath;
    protected PDO $pdo;

    protected function setUp(): void
    {
        $this->dbPath = tempnam(sys_get_temp_dir(), 'svctest_') . '.sqlite';
        $this->pdo = Database::connect($this->dbPath);
    }

    protected function tearDown(): void
    {
        @unlink($this->dbPath);
    }

    protected function createUser(string $email = 'user@example.test'): int
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO users (name, email, password_hash) VALUES (:name, :email, :hash)'
        );
        $stmt->execute([
            'name' => explode('@', $email)[0],
            'email' => $email,
            'hash' => password_hash('password123', PASSWORD_BCRYPT),
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    protected function systemCategoryId(string $name): int
    {
        $stmt = $this->pdo->prepare('SELECT id FROM categories WHERE user_id IS NULL AND name = :name');
        $stmt->execute(['name' => $name]);

        return (int) $stmt->fetchColumn();
    }

    protected function subcategoryId(string $categoryName, string $subcategoryName): int
    {
        $stmt = $this->pdo->prepare(
            'SELECT sc.id FROM subcategories sc
             JOIN categories c ON c.id = sc.category_id
             WHERE c.user_id IS NULL AND c.name = :category AND sc.name = :subcategory'
        );
        $stmt->execute(['category' => $categoryName, 'subcategory' => $subcategoryName]);

        return (int) $stmt->fetchColumn();
    }
}
