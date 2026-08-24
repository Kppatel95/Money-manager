<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Database;
use App\Repositories\ExpenseRepository;
use App\Repositories\UserRepository;
use PDO;
use PHPUnit\Framework\TestCase;

/**
 * Exercises real CRUD against an actual SQLite file (not a mock), so it
 * proves the SQL and the schema actually agree with each other.
 */
final class ExpenseRepositoryTest extends TestCase
{
    private string $dbPath;
    private PDO $pdo;
    private ExpenseRepository $expenses;
    private int $userId;

    protected function setUp(): void
    {
        $this->dbPath = sys_get_temp_dir() . '/expense_tracker_test_' . uniqid() . '.sqlite';
        $this->pdo = Database::connect($this->dbPath);

        $this->expenses = new ExpenseRepository($this->pdo);
        $users = new UserRepository($this->pdo);

        $user = $users->create('Test User', 'test@example.com', password_hash('secret123', PASSWORD_BCRYPT));
        $this->userId = (int) $user['id'];
    }

    protected function tearDown(): void
    {
        unset($this->pdo);

        if (is_file($this->dbPath)) {
            unlink($this->dbPath);
        }
    }

    public function testCreateAndFind(): void
    {
        $expense = $this->expenses->create($this->userId, [
            'amount' => 12.5,
            'category' => 'Food',
            'description' => 'Lunch',
            'expense_date' => '2026-08-01',
        ]);

        self::assertNotNull($expense);
        self::assertSame('Food', $expense['category']);

        $found = $this->expenses->find((int) $expense['id'], $this->userId);
        self::assertNotNull($found);
        self::assertEquals(12.5, $found['amount']);
    }

    public function testAllForUserOnlyReturnsThatUsersExpenses(): void
    {
        $users = new UserRepository($this->pdo);
        $otherUser = $users->create('Other User', 'other@example.com', password_hash('secret123', PASSWORD_BCRYPT));

        $this->expenses->create($this->userId, [
            'amount' => 10,
            'category' => 'Food',
            'description' => null,
            'expense_date' => '2026-08-01',
        ]);
        $this->expenses->create((int) $otherUser['id'], [
            'amount' => 999,
            'category' => 'Food',
            'description' => null,
            'expense_date' => '2026-08-01',
        ]);

        $results = $this->expenses->allForUser($this->userId);

        self::assertCount(1, $results);
        self::assertSame(10.0, (float) $results[0]['amount']);
    }

    public function testFilteringByCategoryAndDateRange(): void
    {
        $this->expenses->create($this->userId, [
            'amount' => 10, 'category' => 'Food', 'description' => null, 'expense_date' => '2026-08-01',
        ]);
        $this->expenses->create($this->userId, [
            'amount' => 20, 'category' => 'Transport', 'description' => null, 'expense_date' => '2026-08-05',
        ]);
        $this->expenses->create($this->userId, [
            'amount' => 30, 'category' => 'Food', 'description' => null, 'expense_date' => '2026-08-10',
        ]);

        $foodOnly = $this->expenses->allForUser($this->userId, ['category' => 'Food']);
        self::assertCount(2, $foodOnly);

        $inRange = $this->expenses->allForUser($this->userId, ['from' => '2026-08-04', 'to' => '2026-08-09']);
        self::assertCount(1, $inRange);
        self::assertSame('Transport', $inRange[0]['category']);
    }

    public function testUpdateOnlyChangesProvidedFieldsAndIsScopedToOwner(): void
    {
        $users = new UserRepository($this->pdo);
        $otherUser = $users->create('Other User', 'other2@example.com', password_hash('secret123', PASSWORD_BCRYPT));

        $expense = $this->expenses->create($this->userId, [
            'amount' => 10,
            'category' => 'Food',
            'description' => 'Original',
            'expense_date' => '2026-08-01',
        ]);

        $updated = $this->expenses->update((int) $expense['id'], $this->userId, ['amount' => 55.25]);

        self::assertSame(55.25, (float) $updated['amount']);
        self::assertSame('Original', $updated['description']); // untouched
        self::assertSame('Food', $updated['category']); // untouched

        // Another user cannot update someone else's expense.
        $result = $this->expenses->update((int) $expense['id'], (int) $otherUser['id'], ['amount' => 1]);
        self::assertNull($result);
    }

    public function testDeleteRemovesExpenseAndIsScopedToOwner(): void
    {
        $users = new UserRepository($this->pdo);
        $otherUser = $users->create('Other User', 'other3@example.com', password_hash('secret123', PASSWORD_BCRYPT));

        $expense = $this->expenses->create($this->userId, [
            'amount' => 10, 'category' => 'Food', 'description' => null, 'expense_date' => '2026-08-01',
        ]);

        $deletedByWrongUser = $this->expenses->delete((int) $expense['id'], (int) $otherUser['id']);
        self::assertFalse($deletedByWrongUser);

        $deleted = $this->expenses->delete((int) $expense['id'], $this->userId);
        self::assertTrue($deleted);

        self::assertNull($this->expenses->find((int) $expense['id'], $this->userId));
    }

    public function testSummaryByCategoryGroupsAndSumsCorrectly(): void
    {
        $this->expenses->create($this->userId, [
            'amount' => 10, 'category' => 'Food', 'description' => null, 'expense_date' => '2026-08-01',
        ]);
        $this->expenses->create($this->userId, [
            'amount' => 15, 'category' => 'Food', 'description' => null, 'expense_date' => '2026-08-02',
        ]);
        $this->expenses->create($this->userId, [
            'amount' => 40, 'category' => 'Rent', 'description' => null, 'expense_date' => '2026-08-03',
        ]);

        $summary = $this->expenses->summaryByCategory($this->userId);

        $byCategory = [];
        foreach ($summary as $row) {
            $byCategory[$row['category']] = $row;
        }

        self::assertSame(25.0, $byCategory['Food']['total']);
        self::assertSame(2, $byCategory['Food']['count']);
        self::assertSame(40.0, $byCategory['Rent']['total']);
        self::assertSame(1, $byCategory['Rent']['count']);
    }
}
