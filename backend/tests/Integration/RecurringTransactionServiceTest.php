<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Exceptions\NotFoundException;
use App\Exceptions\ValidationException;
use App\Repositories\AccountRepository;
use App\Repositories\CategoryRepository;
use App\Repositories\RecurringTransactionRepository;
use App\Repositories\SubcategoryRepository;
use App\Repositories\TransactionRepository;
use App\Services\AccountService;
use App\Services\CategoryService;
use App\Services\RecurringTransactionService;
use App\Services\SubcategoryService;
use App\Services\TransactionService;
use Tests\Support\ServiceTestCase;

final class RecurringTransactionServiceTest extends ServiceTestCase
{
    private RecurringTransactionService $recurring;
    private RecurringTransactionRepository $repository;
    private TransactionService $transactions;
    private AccountService $accounts;
    private int $userId;
    private int $accountId;
    private int $billsId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->accounts = new AccountService(new AccountRepository($this->pdo));
        $categories = new CategoryService(new CategoryRepository($this->pdo));
        $transactionRepository = new TransactionRepository($this->pdo);
        $this->repository = new RecurringTransactionRepository($this->pdo);

        $subcategories = new SubcategoryService(new SubcategoryRepository($this->pdo));
        $this->transactions = new TransactionService($transactionRepository, $this->accounts, $categories, $subcategories);
        $this->recurring = new RecurringTransactionService(
            $this->repository,
            $transactionRepository,
            $this->accounts,
            $categories
        );

        $this->userId = $this->createUser();
        $this->accountId = $this->accounts->create($this->userId, [
            'name' => 'Checking', 'type' => 'bank', 'initial_balance' => '1000.00',
        ])['id'];
        $this->billsId = $this->systemCategoryId('Bills');
    }

    public function testRunDueCreatesATransactionAndAdvancesTheSchedule(): void
    {
        $schedule = $this->schedule('2026-03-01', 'monthly', '850.00');

        $created = $this->recurring->runDue($this->userId, '2026-03-01');

        $this->assertCount(1, $created);
        $this->assertSame(85000, (int) $created[0]['amount']);
        $this->assertSame('2026-03-01', $created[0]['transaction_date']);

        $refreshed = $this->repository->findForUser($schedule['id'], $this->userId);
        $this->assertSame('2026-04-01', $refreshed['next_run_date']);

        $this->assertSame(1, $this->transactions->list($this->userId, [])['meta']['total']);
        $this->assertSame(
            100000 - 85000,
            $this->accounts->balance($this->userId, $this->accountId)['balance_cents']
        );
    }

    public function testRunDueIsANoOpBeforeTheDueDate(): void
    {
        $this->schedule('2026-03-01', 'monthly', '850.00');

        $this->assertSame([], $this->recurring->runDue($this->userId, '2026-02-27'));
        $this->assertSame(0, $this->transactions->list($this->userId, [])['meta']['total']);
    }

    public function testRunningTwiceOnTheSameDayDoesNotDuplicate(): void
    {
        $this->schedule('2026-03-01', 'monthly', '850.00');

        $this->recurring->runDue($this->userId, '2026-03-01');
        $this->recurring->runDue($this->userId, '2026-03-01');

        $this->assertSame(1, $this->transactions->list($this->userId, [])['meta']['total']);
    }

    public function testCatchesUpEveryMissedOccurrence(): void
    {
        $this->schedule('2026-01-01', 'monthly', '100.00');

        $created = $this->recurring->runDue($this->userId, '2026-04-15');

        $this->assertCount(4, $created); // Jan, Feb, Mar, Apr
        $this->assertSame(
            ['2026-01-01', '2026-02-01', '2026-03-01', '2026-04-01'],
            array_column($created, 'transaction_date')
        );
    }

    public function testWeeklyCatchUpUsesSevenDaySteps(): void
    {
        $this->schedule('2026-03-02', 'weekly', '15.00');

        $created = $this->recurring->runDue($this->userId, '2026-03-20');

        $this->assertSame(['2026-03-02', '2026-03-09', '2026-03-16'], array_column($created, 'transaction_date'));
    }

    public function testInactiveSchedulesAreSkipped(): void
    {
        $schedule = $this->schedule('2026-03-01', 'monthly', '850.00');
        $this->recurring->update($this->userId, $schedule['id'], ['active' => false]);

        $this->assertSame([], $this->recurring->runDue($this->userId, '2026-06-01'));
    }

    public function testGeneratedTransactionsAreTaggedAndDescribed(): void
    {
        $this->schedule('2026-03-01', 'monthly', '850.00', 'Rent');

        $this->recurring->runDue($this->userId, '2026-03-01');
        $transaction = $this->transactions->list($this->userId, [])['data'][0];

        $this->assertSame('Rent', $transaction['description']);
        $this->assertSame(['recurring'], $transaction['tags']);
        $this->assertSame('Bills', $transaction['category_name']);
    }

    public function testArchivingTheAccountPausesTheScheduleInsteadOfFailing(): void
    {
        $schedule = $this->schedule('2026-03-01', 'monthly', '850.00');
        $this->recurring->runDue($this->userId, '2026-03-01'); // gives the account history
        $this->accounts->delete($this->userId, $this->accountId); // archives it

        $created = $this->recurring->runDue($this->userId, '2026-05-01');

        $this->assertSame([], $created);
        $this->assertFalse($this->repository->findForUser($schedule['id'], $this->userId)['active'] === 1);
    }

    public function testRunDueOnlyTouchesTheGivenUser(): void
    {
        $other = $this->createUser('other@example.test');
        $otherAccount = $this->accounts->create($other, ['name' => 'Theirs', 'type' => 'cash'])['id'];
        $this->recurring->create($other, [
            'type' => 'expense',
            'account_id' => $otherAccount,
            'category_id' => $this->billsId,
            'amount' => '10.00',
            'description' => 'Their bill',
            'frequency' => 'monthly',
            'next_run_date' => '2026-03-01',
        ]);

        $this->assertSame([], $this->recurring->runDue($this->userId, '2026-06-01'));
        $this->assertSame(0, $this->transactions->list($this->userId, [])['meta']['total']);
    }

    public function testRejectsUnknownFrequencies(): void
    {
        $this->expectException(ValidationException::class);
        $this->recurring->create($this->userId, [
            'type' => 'expense',
            'account_id' => $this->accountId,
            'category_id' => $this->billsId,
            'amount' => '10.00',
            'frequency' => 'fortnightly',
            'next_run_date' => '2026-03-01',
        ]);
    }

    public function testAnotherUsersScheduleIsReportedAsMissing(): void
    {
        $other = $this->createUser('other@example.test');
        $otherAccount = $this->accounts->create($other, ['name' => 'Theirs', 'type' => 'cash'])['id'];
        $theirs = $this->recurring->create($other, [
            'type' => 'expense',
            'account_id' => $otherAccount,
            'category_id' => $this->billsId,
            'amount' => '10.00',
            'frequency' => 'monthly',
            'next_run_date' => '2026-03-01',
        ]);

        $this->assertSame([], $this->recurring->list($this->userId));

        $this->expectException(NotFoundException::class);
        $this->recurring->delete($this->userId, $theirs['id']);
    }

    /** @return array<string, mixed> */
    private function schedule(string $nextRun, string $frequency, string $amount, string $description = 'Rent'): array
    {
        return $this->recurring->create($this->userId, [
            'type' => 'expense',
            'account_id' => $this->accountId,
            'category_id' => $this->billsId,
            'amount' => $amount,
            'description' => $description,
            'frequency' => $frequency,
            'next_run_date' => $nextRun,
        ]);
    }
}
