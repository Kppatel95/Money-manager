<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Database;
use App\Repositories\UserRepository;
use PDO;
use PHPUnit\Framework\TestCase;

final class UserRepositoryTest extends TestCase
{
    private string $dbPath;
    private PDO $pdo;
    private UserRepository $users;

    protected function setUp(): void
    {
        $this->dbPath = sys_get_temp_dir() . '/expense_tracker_test_' . uniqid() . '.sqlite';
        $this->pdo = Database::connect($this->dbPath);
        $this->users = new UserRepository($this->pdo);
    }

    protected function tearDown(): void
    {
        unset($this->pdo);

        if (is_file($this->dbPath)) {
            unlink($this->dbPath);
        }
    }

    public function testCreateAndFindByEmail(): void
    {
        $hash = password_hash('secret123', PASSWORD_BCRYPT);
        $created = $this->users->create('Jane Doe', 'jane@example.com', $hash);

        self::assertNotNull($created);
        self::assertSame('Jane Doe', $created['name']);

        $found = $this->users->findByEmail('jane@example.com');
        self::assertNotNull($found);
        self::assertSame($created['id'], $found['id']);
        self::assertTrue(password_verify('secret123', $found['password_hash']));
    }

    public function testFindByEmailReturnsNullWhenNotFound(): void
    {
        self::assertNull($this->users->findByEmail('nobody@example.com'));
    }

    public function testDuplicateEmailViolatesUniqueConstraint(): void
    {
        $hash = password_hash('secret123', PASSWORD_BCRYPT);
        $this->users->create('Jane Doe', 'jane@example.com', $hash);

        $this->expectException(\PDOException::class);
        $this->users->create('Someone Else', 'jane@example.com', $hash);
    }
}
