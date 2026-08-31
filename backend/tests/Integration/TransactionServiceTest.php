<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Exceptions\NotFoundException;
use App\Exceptions\ValidationException;
use App\Repositories\AccountRepository;
use App\Repositories\CategoryRepository;
use App\Repositories\SubcategoryRepository;
use App\Repositories\TransactionRepository;
use App\Services\AccountService;
use App\Services\CategoryService;
use App\Services\SubcategoryService;
use App\Services\TransactionService;
use Tests\Support\ServiceTestCase;

final class TransactionServiceTest extends ServiceTestCase
{
    private TransactionService $service;
    private AccountService $accounts;
    private int $userId;
    private int $checkingId;
    private int $savingsId;
    private int $foodId;
    private int $salaryId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->accounts = new AccountService(new AccountRepository($this->pdo));
        $categories = new CategoryService(new CategoryRepository($this->pdo));
        $subcategories = new SubcategoryService(new SubcategoryRepository($this->pdo));
        $this->service = new TransactionService(
            new TransactionRepository($this->pdo),
            $this->accounts,
            $categories,
            $subcategories
        );

        $this->userId = $this->createUser();
        $this->checkingId = $this->accounts->create($this->userId, [
            'name' => 'Checking', 'type' => 'bank', 'initial_balance' => '1000.00',
        ])['id'];
        $this->savingsId = $this->accounts->create($this->userId, [
            'name' => 'Savings', 'type' => 'savings',
        ])['id'];
        $this->foodId = $this->systemCategoryId('Food');
        $this->salaryId = $this->systemCategoryId('Salary');
    }

    public function testRecordingAnExpenseLowersTheAccountBalance(): void
    {
        $transaction = $this->service->create($this->userId, [
            'type' => 'expense',
            'account_id' => $this->checkingId,
            'category_id' => $this->foodId,
            'amount' => '42.55',
            'description' => 'Groceries',
            'transaction_date' => '2026-03-04',
            'tags' => ['weekly', 'household'],
        ]);

        $this->assertSame(4255, $transaction['amount_cents']);
        $this->assertSame(42.55, $transaction['amount']);
        $this->assertSame('Food', $transaction['category_name']);
        $this->assertSame(['weekly', 'household'], $transaction['tags']);

        $this->assertSame(
            100000 - 4255,
            $this->accounts->balance($this->userId, $this->checkingId)['balance_cents']
        );
    }

    public function testRecordingAnExpenseWithASubcategory(): void
    {
        $groceriesId = $this->subcategoryId('Food', 'Groceries');

        $transaction = $this->service->create($this->userId, [
            'type' => 'expense',
            'account_id' => $this->checkingId,
            'category_id' => $this->foodId,
            'subcategory_id' => $groceriesId,
            'amount' => '42.55',
            'description' => 'Groceries',
            'transaction_date' => '2026-03-04',
        ]);

        $this->assertSame($groceriesId, $transaction['subcategory_id']);
        $this->assertSame('Groceries', $transaction['subcategory_name']);
    }

    public function testRejectsASubcategoryThatBelongsToAnotherCategory(): void
    {
        $transportSubId = $this->subcategoryId('Transport', 'Fuel');

        $this->expectException(ValidationException::class);
        $this->service->create($this->userId, [
            'type' => 'expense',
            'account_id' => $this->checkingId,
            'category_id' => $this->foodId,
            'subcategory_id' => $transportSubId,
            'amount' => '10.00',
            'description' => 'Groceries',
            'transaction_date' => '2026-03-04',
        ]);
    }

    public function testRecordingIncomeRaisesTheAccountBalance(): void
    {
        $this->service->create($this->userId, [
            'type' => 'income',
            'account_id' => $this->checkingId,
            'category_id' => $this->salaryId,
            'amount' => 2500,
            'description' => 'March salary',
            'transaction_date' => '2026-03-01',
        ]);

        $this->assertSame(
            100000 + 250000,
            $this->accounts->balance($this->userId, $this->checkingId)['balance_cents']
        );
    }

    public function testATransferMovesMoneyBetweenTwoAccounts(): void
    {
        $this->service->create($this->userId, [
            'type' => 'transfer',
            'account_id' => $this->checkingId,
            'transfer_to_account_id' => $this->savingsId,
            'amount' => '250.00',
            'description' => 'Monthly saving',
            'transaction_date' => '2026-03-05',
        ]);

        $this->assertSame(75000, $this->accounts->balance($this->userId, $this->checkingId)['balance_cents']);
        $this->assertSame(25000, $this->accounts->balance($this->userId, $this->savingsId)['balance_cents']);
    }

    public function testATransferMayNotTargetItsOwnAccountOrCarryACategory(): void
    {
        try {
            $this->service->create($this->userId, [
                'type' => 'transfer',
                'account_id' => $this->checkingId,
                'transfer_to_account_id' => $this->checkingId,
                'category_id' => $this->foodId,
                'amount' => '10.00',
                'transaction_date' => '2026-03-05',
            ]);
            $this->fail('Expected a ValidationException.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('transfer_to_account_id', $e->errors());
            $this->assertArrayHasKey('category_id', $e->errors());
        }
    }

    public function testIncomeAndExpenseRequireACategoryOfTheMatchingType(): void
    {
        try {
            $this->service->create($this->userId, [
                'type' => 'expense',
                'account_id' => $this->checkingId,
                'category_id' => $this->salaryId, // an income category
                'amount' => '5.00',
                'transaction_date' => '2026-03-05',
            ]);
            $this->fail('Expected a ValidationException.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('category_id', $e->errors());
        }
    }

    public function testRejectsZeroAndNegativeAmounts(): void
    {
        $this->expectException(ValidationException::class);
        $this->service->create($this->userId, [
            'type' => 'expense',
            'account_id' => $this->checkingId,
            'category_id' => $this->foodId,
            'amount' => '0',
            'transaction_date' => '2026-03-05',
        ]);
    }

    public function testEditingAnAmountRestatesTheBalance(): void
    {
        $transaction = $this->expense('30.00', '2026-03-04');

        $this->service->update($this->userId, $transaction['id'], ['amount' => '10.00']);

        $this->assertSame(
            100000 - 1000,
            $this->accounts->balance($this->userId, $this->checkingId)['balance_cents']
        );
    }

    public function testDeletingATransactionRestoresTheBalance(): void
    {
        $transaction = $this->expense('30.00', '2026-03-04');

        $this->service->delete($this->userId, $transaction['id']);

        $this->assertSame(100000, $this->accounts->balance($this->userId, $this->checkingId)['balance_cents']);
        $this->assertSame(0, $this->service->list($this->userId, [])['meta']['total']);
    }

    public function testChangingTypeToTransferClearsTheCategory(): void
    {
        $transaction = $this->expense('30.00', '2026-03-04');

        $updated = $this->service->update($this->userId, $transaction['id'], [
            'type' => 'transfer',
            'transfer_to_account_id' => $this->savingsId,
        ]);

        $this->assertNull($updated['category_id']);
        $this->assertSame($this->savingsId, $updated['transfer_to_account_id']);
    }

    public function testListFiltersByTypeCategoryDateAndSearch(): void
    {
        $this->expense('10.00', '2026-01-10', 'Coffee beans');
        $this->expense('20.00', '2026-02-10', 'Restaurant lunch');
        $this->service->create($this->userId, [
            'type' => 'income',
            'account_id' => $this->checkingId,
            'category_id' => $this->salaryId,
            'amount' => '1000.00',
            'description' => 'January salary',
            'transaction_date' => '2026-01-31',
        ]);

        $this->assertSame(3, $this->service->list($this->userId, [])['meta']['total']);
        $this->assertSame(2, $this->service->list($this->userId, ['type' => 'expense'])['meta']['total']);
        $this->assertSame(1, $this->service->list($this->userId, ['category_id' => $this->salaryId])['meta']['total']);
        $this->assertSame(
            2,
            $this->service->list($this->userId, ['date_from' => '2026-01-01', 'date_to' => '2026-01-31'])['meta']['total']
        );
        $this->assertSame(1, $this->service->list($this->userId, ['search' => 'lunch'])['meta']['total']);
        $this->assertSame(1, $this->service->list($this->userId, ['search' => 'salary'])['meta']['total']);
    }

    public function testSearchAlsoLooksInNotes(): void
    {
        $this->service->create($this->userId, [
            'type' => 'expense',
            'account_id' => $this->checkingId,
            'category_id' => $this->foodId,
            'amount' => '8.00',
            'description' => 'Lunch',
            'notes' => 'Reimbursable by the client',
            'transaction_date' => '2026-03-06',
        ]);

        $this->assertSame(1, $this->service->list($this->userId, ['search' => 'reimbursable'])['meta']['total']);
    }

    public function testRejectsNonsenseFilterValues(): void
    {
        $this->expectException(ValidationException::class);
        $this->service->list($this->userId, ['type' => 'refund']);
    }

    public function testPaginatesNewestFirst(): void
    {
        for ($day = 1; $day <= 7; $day++) {
            $this->expense('5.00', sprintf('2026-04-%02d', $day), "Day {$day}");
        }

        $firstPage = $this->service->list($this->userId, [], page: 1, perPage: 3);

        $this->assertCount(3, $firstPage['data']);
        $this->assertSame(7, $firstPage['meta']['total']);
        $this->assertSame(3, $firstPage['meta']['total_pages']);
        $this->assertSame('2026-04-07', $firstPage['data'][0]['transaction_date']);

        $lastPage = $this->service->list($this->userId, [], page: 3, perPage: 3);
        $this->assertCount(1, $lastPage['data']);
        $this->assertSame('2026-04-01', $lastPage['data'][0]['transaction_date']);
    }

    public function testTransfersAppearUnderBothAccounts(): void
    {
        $this->service->create($this->userId, [
            'type' => 'transfer',
            'account_id' => $this->checkingId,
            'transfer_to_account_id' => $this->savingsId,
            'amount' => '100.00',
            'transaction_date' => '2026-03-05',
        ]);

        $this->assertSame(1, $this->service->list($this->userId, ['account_id' => $this->checkingId])['meta']['total']);
        $this->assertSame(1, $this->service->list($this->userId, ['account_id' => $this->savingsId])['meta']['total']);
    }

    public function testCannotPostToAnotherUsersAccount(): void
    {
        $other = $this->createUser('other@example.test');
        $theirAccount = $this->accounts->create($other, ['name' => 'Theirs', 'type' => 'cash'])['id'];

        $this->expectException(NotFoundException::class);
        $this->service->create($this->userId, [
            'type' => 'expense',
            'account_id' => $theirAccount,
            'category_id' => $this->foodId,
            'amount' => '1.00',
            'transaction_date' => '2026-03-05',
        ]);
    }

    public function testCannotReadOrEditAnotherUsersTransaction(): void
    {
        $other = $this->createUser('other@example.test');
        $theirAccount = $this->accounts->create($other, ['name' => 'Theirs', 'type' => 'cash'])['id'];
        $theirs = $this->service->create($other, [
            'type' => 'expense',
            'account_id' => $theirAccount,
            'category_id' => $this->foodId,
            'amount' => '9.99',
            'transaction_date' => '2026-03-05',
        ]);

        $this->assertSame(0, $this->service->list($this->userId, [])['meta']['total']);

        $this->expectException(NotFoundException::class);
        $this->service->get($this->userId, $theirs['id']);
    }

    public function testArchivedAccountsRejectNewTransactions(): void
    {
        $this->expense('5.00', '2026-03-04');
        $this->accounts->delete($this->userId, $this->checkingId); // archives it

        $this->expectException(ValidationException::class);
        $this->expense('5.00', '2026-03-05');
    }

    /** @return array<string, mixed> */
    private function expense(string $amount, string $date, string $description = 'Groceries'): array
    {
        return $this->service->create($this->userId, [
            'type' => 'expense',
            'account_id' => $this->checkingId,
            'category_id' => $this->foodId,
            'amount' => $amount,
            'description' => $description,
            'transaction_date' => $date,
        ]);
    }
}
