<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Migration\Migrator;
use PDO;
use PHPUnit\Framework\TestCase;

/**
 * The v1 -> v2 data migration: expenses recorded against the old single table
 * must survive as real transactions rather than being dropped on the floor.
 *
 * The test applies the migrations up to the point where the legacy table
 * still exists, writes v1 rows, then lets the remaining migrations run.
 */
final class LegacyDataMigrationTest extends TestCase
{
    private string $dbPath;
    private PDO $pdo;
    private string $stagingDir;

    protected function setUp(): void
    {
        $this->dbPath = tempnam(sys_get_temp_dir(), 'legacy_') . '.sqlite';
        $this->pdo = new PDO('sqlite:' . $this->dbPath);
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

        $this->stagingDir = sys_get_temp_dir() . '/migrations_' . bin2hex(random_bytes(6));
        mkdir($this->stagingDir);
    }

    protected function tearDown(): void
    {
        @unlink($this->dbPath);
        foreach (glob($this->stagingDir . '/*.sql') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($this->stagingDir);
    }

    public function testOldExpensesBecomeTransactionsOnAnImportedAccount(): void
    {
        $this->applyMigrationsUpTo('0009');

        $this->pdo->exec("INSERT INTO users (name, email, password_hash) VALUES ('Ada', 'ada@example.test', 'x')");
        $this->pdo->exec(
            "INSERT INTO expenses (user_id, amount, category, description, expense_date)
             VALUES (1, 12.34, 'Food', 'Lunch', '2026-02-01'),
                    (1, 5.00, 'Mystery', 'Something', '2026-02-02')"
        );

        // Now let every remaining migration run, including the data move.
        (new Migrator($this->pdo, Migrator::defaultPath()))->migrate();

        $tables = $this->pdo->query("SELECT name FROM sqlite_master WHERE type = 'table'")->fetchAll(PDO::FETCH_COLUMN);
        $this->assertNotContains('expenses', $tables, 'the legacy table is gone');

        $transactions = $this->pdo->query(
            'SELECT t.*, a.name AS account_name, c.name AS category_name
             FROM transactions t
             JOIN accounts a ON a.id = t.account_id
             LEFT JOIN categories c ON c.id = t.category_id
             ORDER BY t.transaction_date'
        )->fetchAll();

        $this->assertCount(2, $transactions);
        $this->assertSame('Imported', $transactions[0]['account_name']);
        $this->assertSame(1234, (int) $transactions[0]['amount'], 'amounts become integer cents');
        $this->assertSame('Food', $transactions[0]['category_name'], 'known categories are matched by name');
        $this->assertSame('Other', $transactions[1]['category_name'], 'unknown ones fall back to Other');
        $this->assertSame('expense', $transactions[1]['type']);
    }

    /** Copies the migration files up to and including $lastPrefix, then runs them. */
    private function applyMigrationsUpTo(string $lastPrefix): void
    {
        foreach (glob(Migrator::defaultPath() . '/*.sql') ?: [] as $file) {
            $name = basename($file);

            if (substr($name, 0, 4) <= $lastPrefix) {
                copy($file, $this->stagingDir . '/' . $name);
            }
        }

        (new Migrator($this->pdo, $this->stagingDir))->migrate();
    }
}
