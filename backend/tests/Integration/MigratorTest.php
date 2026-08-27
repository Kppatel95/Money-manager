<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Migration\Migrator;
use PDO;
use PHPUnit\Framework\TestCase;

final class MigratorTest extends TestCase
{
    private string $dbPath;
    private PDO $pdo;

    protected function setUp(): void
    {
        $this->dbPath = tempnam(sys_get_temp_dir(), 'migrator_') . '.sqlite';
        $this->pdo = new PDO('sqlite:' . $this->dbPath);
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    }

    protected function tearDown(): void
    {
        @unlink($this->dbPath);
    }

    private function migrator(): Migrator
    {
        return new Migrator($this->pdo, Migrator::defaultPath());
    }

    public function testAppliesEveryMigrationOnAFreshDatabase(): void
    {
        $applied = $this->migrator()->migrate();

        $this->assertNotEmpty($applied);
        $this->assertSame('0001_create_users.sql', $applied[0]);

        $tables = $this->pdo
            ->query("SELECT name FROM sqlite_master WHERE type = 'table'")
            ->fetchAll(PDO::FETCH_COLUMN);

        foreach (['users', 'accounts', 'categories', 'transactions', 'budgets', 'recurring_transactions', 'refresh_tokens', 'login_attempts'] as $table) {
            $this->assertContains($table, $tables, "expected table {$table}");
        }
    }

    public function testIsIdempotent(): void
    {
        $first = $this->migrator()->migrate();
        $second = $this->migrator()->migrate();

        $this->assertNotEmpty($first);
        $this->assertSame([], $second);
        $this->assertSame([], $this->migrator()->pending());
    }

    public function testSeedsSystemCategoriesOwnedByNobody(): void
    {
        $this->migrator()->migrate();

        $count = (int) $this->pdo
            ->query('SELECT COUNT(*) FROM categories WHERE user_id IS NULL')
            ->fetchColumn();

        $this->assertGreaterThanOrEqual(8, $count);
    }
}
