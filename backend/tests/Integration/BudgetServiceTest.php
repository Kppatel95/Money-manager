<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Exceptions\ConflictException;
use App\Exceptions\NotFoundException;
use App\Exceptions\ValidationException;
use App\Repositories\AccountRepository;
use App\Repositories\BudgetRepository;
use App\Repositories\CategoryRepository;
use App\Repositories\SubcategoryRepository;
use App\Repositories\TransactionRepository;
use App\Services\AccountService;
use App\Services\BudgetService;
use App\Services\CategoryService;
use App\Services\SubcategoryService;
use App\Services\TransactionService;
use Tests\Support\ServiceTestCase;

final class BudgetServiceTest extends ServiceTestCase
{
    private BudgetService $budgets;
    private TransactionService $transactions;
    private int $userId;
    private int $accountId;
    private int $foodId;
    private int $transportId;

    protected function setUp(): void
    {
        parent::setUp();

        $accounts = new AccountService(new AccountRepository($this->pdo));
        $categories = new CategoryService(new CategoryRepository($this->pdo));
        $transactionRepository = new TransactionRepository($this->pdo);

        $subcategories = new SubcategoryService(new SubcategoryRepository($this->pdo));
        $this->transactions = new TransactionService($transactionRepository, $accounts, $categories, $subcategories);
        $this->budgets = new BudgetService(new BudgetRepository($this->pdo), $transactionRepository, $categories);

        $this->userId = $this->createUser();
        $this->accountId = $accounts->create($this->userId, ['name' => 'Checking', 'type' => 'bank'])['id'];
        $this->foodId = $this->systemCategoryId('Food');
        $this->transportId = $this->systemCategoryId('Transport');
    }

    public function testComputesSpentAndRemainingFromRealTransactions(): void
    {
        $this->budgets->create($this->userId, [
            'category_id' => $this->foodId,
            'month' => '2026-05',
            'amount_limit' => '400.00',
        ]);

        $this->spend($this->foodId, '120.50', '2026-05-03');
        $this->spend($this->foodId, '79.50', '2026-05-20');
        $this->spend($this->foodId, '999.00', '2026-06-01');       // different month
        $this->spend($this->transportId, '50.00', '2026-05-04');   // different category

        $budget = $this->budgets->listForMonth($this->userId, '2026-05')['data'][0];

        $this->assertSame(40000, $budget['amount_limit_cents']);
        $this->assertSame(20000, $budget['spent_cents']);
        $this->assertSame(20000, $budget['remaining_cents']);
        $this->assertSame(200.0, $budget['spent']);
        $this->assertSame(50.0, $budget['percent_used']);
        $this->assertFalse($budget['over_budget']);
    }

    public function testFlagsAnOverspentBudgetWithANegativeRemainder(): void
    {
        $this->budgets->create($this->userId, [
            'category_id' => $this->foodId,
            'month' => '2026-05',
            'amount_limit' => '100.00',
        ]);
        $this->spend($this->foodId, '130.00', '2026-05-09');

        $budget = $this->budgets->listForMonth($this->userId, '2026-05')['data'][0];

        $this->assertSame(-3000, $budget['remaining_cents']);
        $this->assertSame(130.0, $budget['percent_used']);
        $this->assertTrue($budget['over_budget']);
    }

    public function testMonthTotalsAreSummarisedInTheMeta(): void
    {
        $this->budgets->create($this->userId, ['category_id' => $this->foodId, 'month' => '2026-05', 'amount_limit' => '300.00']);
        $this->budgets->create($this->userId, ['category_id' => $this->transportId, 'month' => '2026-05', 'amount_limit' => '100.00']);
        $this->spend($this->foodId, '60.00', '2026-05-02');
        $this->spend($this->transportId, '40.00', '2026-05-02');

        $meta = $this->budgets->listForMonth($this->userId, '2026-05')['meta'];

        $this->assertSame(40000, $meta['total_limit_cents']);
        $this->assertSame(10000, $meta['total_spent_cents']);
        $this->assertSame(30000, $meta['total_remaining_cents']);
    }

    public function testIncomeDoesNotCountAgainstABudget(): void
    {
        $this->budgets->create($this->userId, ['category_id' => $this->foodId, 'month' => '2026-05', 'amount_limit' => '100.00']);

        $this->transactions->create($this->userId, [
            'type' => 'income',
            'account_id' => $this->accountId,
            'category_id' => $this->systemCategoryId('Salary'),
            'amount' => '2000.00',
            'transaction_date' => '2026-05-01',
        ]);

        $this->assertSame(0, $this->budgets->listForMonth($this->userId, '2026-05')['data'][0]['spent_cents']);
    }

    public function testOneBudgetPerCategoryPerMonth(): void
    {
        $this->budgets->create($this->userId, ['category_id' => $this->foodId, 'month' => '2026-05', 'amount_limit' => '100.00']);

        $this->expectException(ConflictException::class);
        $this->budgets->create($this->userId, ['category_id' => $this->foodId, 'month' => '2026-05', 'amount_limit' => '200.00']);
    }

    public function testTheSameCategoryCanBeBudgetedInAnotherMonth(): void
    {
        $this->budgets->create($this->userId, ['category_id' => $this->foodId, 'month' => '2026-05', 'amount_limit' => '100.00']);
        $june = $this->budgets->create($this->userId, ['category_id' => $this->foodId, 'month' => '2026-06', 'amount_limit' => '150.00']);

        $this->assertSame('2026-06', $june['month']);
    }

    public function testRejectsIncomeCategoriesAndBadMonths(): void
    {
        try {
            $this->budgets->create($this->userId, [
                'category_id' => $this->foodId,
                'month' => 'May 2026',
                'amount_limit' => '100.00',
            ]);
            $this->fail('Expected a ValidationException.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('month', $e->errors());
        }

        $this->expectException(ValidationException::class);
        $this->budgets->create($this->userId, [
            'category_id' => $this->systemCategoryId('Salary'),
            'month' => '2026-05',
            'amount_limit' => '100.00',
        ]);
    }

    public function testUpdatingTheLimitRecomputesTheRemainder(): void
    {
        $budget = $this->budgets->create($this->userId, [
            'category_id' => $this->foodId, 'month' => '2026-05', 'amount_limit' => '100.00',
        ]);
        $this->spend($this->foodId, '80.00', '2026-05-09');

        $updated = $this->budgets->update($this->userId, $budget['id'], ['amount_limit' => '200.00']);

        $this->assertSame(20000, $updated['amount_limit_cents']);
        $this->assertSame(8000, $updated['spent_cents']);
        $this->assertSame(12000, $updated['remaining_cents']);
    }

    public function testDeletingABudgetLeavesTheTransactionsAlone(): void
    {
        $budget = $this->budgets->create($this->userId, [
            'category_id' => $this->foodId, 'month' => '2026-05', 'amount_limit' => '100.00',
        ]);
        $this->spend($this->foodId, '20.00', '2026-05-09');

        $this->budgets->delete($this->userId, $budget['id']);

        $this->assertSame([], $this->budgets->listForMonth($this->userId, '2026-05')['data']);
        $this->assertSame(1, $this->transactions->list($this->userId, [])['meta']['total']);
    }

    public function testAnotherUsersBudgetIsReportedAsMissing(): void
    {
        $other = $this->createUser('other@example.test');
        $theirs = $this->budgets->create($other, [
            'category_id' => $this->foodId, 'month' => '2026-05', 'amount_limit' => '100.00',
        ]);

        $this->assertSame([], $this->budgets->listForMonth($this->userId, '2026-05')['data']);

        $this->expectException(NotFoundException::class);
        $this->budgets->update($this->userId, $theirs['id'], ['amount_limit' => '1.00']);
    }

    public function testDefaultsToTheCurrentMonth(): void
    {
        $this->budgets->create($this->userId, [
            'category_id' => $this->foodId,
            'month' => date('Y-m'),
            'amount_limit' => '100.00',
        ]);

        $result = $this->budgets->listForMonth($this->userId, null);

        $this->assertSame(date('Y-m'), $result['meta']['month']);
        $this->assertCount(1, $result['data']);
    }

    private function spend(int $categoryId, string $amount, string $date): void
    {
        $this->transactions->create($this->userId, [
            'type' => 'expense',
            'account_id' => $this->accountId,
            'category_id' => $categoryId,
            'amount' => $amount,
            'description' => 'spend',
            'transaction_date' => $date,
        ]);
    }
}
