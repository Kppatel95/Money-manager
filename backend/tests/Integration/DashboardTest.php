<?php

declare(strict_types=1);

namespace Tests\Integration;

use Tests\Support\ApiTestCase;

/**
 * Dashboard aggregation through the HTTP layer, against real ledger rows.
 */
final class DashboardTest extends ApiTestCase
{
    private string $token;
    private int $checkingId;
    private int $savingsId;
    private int $foodId;
    private int $transportId;
    private int $salaryId;

    protected function setUp(): void
    {
        parent::setUp();

        $user = $this->registerUser();
        $this->token = $user['token'];

        $this->checkingId = $this->createAccount($this->token, 'Checking', 'bank', 2000.00)['id'];
        $this->savingsId = $this->createAccount($this->token, 'Savings', 'savings', 5000.00)['id'];
        $this->foodId = $this->categoryId($this->token, 'Food');
        $this->transportId = $this->categoryId($this->token, 'Transport');
        $this->salaryId = $this->categoryId($this->token, 'Salary');
    }

    public function testSummaryReportsNetWorthAcrossAccounts(): void
    {
        $summary = $this->summary('2026-05');

        $this->assertSame(700000, $summary['net_worth_cents']);
        $this->assertEquals(7000.0, $summary['net_worth']);
        $this->assertCount(2, $summary['accounts']);
    }

    public function testSummaryReportsThisMonthsIncomeAndExpenseTotals(): void
    {
        $this->income($this->salaryId, '3000.00', '2026-05-01');
        $this->expense($this->foodId, '250.00', '2026-05-04');
        $this->expense($this->transportId, '150.00', '2026-05-06');
        $this->expense($this->foodId, '999.00', '2026-04-30'); // previous month

        $totals = $this->summary('2026-05')['totals'];

        $this->assertSame(300000, $totals['income_cents']);
        $this->assertSame(40000, $totals['expense_cents']);
        $this->assertSame(260000, $totals['net_cents']);
        $this->assertSame(86.7, $totals['savings_rate']);
    }

    public function testTransfersAreExcludedFromIncomeAndExpense(): void
    {
        $this->post('/api/v1/transactions', [
            'type' => 'transfer',
            'account_id' => $this->checkingId,
            'transfer_to_account_id' => $this->savingsId,
            'amount' => '500.00',
            'transaction_date' => '2026-05-10',
        ], $this->token);

        $totals = $this->summary('2026-05')['totals'];

        $this->assertSame(0, $totals['income_cents']);
        $this->assertSame(0, $totals['expense_cents']);
        // Moving money between your own accounts leaves net worth unchanged.
        $this->assertSame(700000, $this->summary('2026-05')['net_worth_cents']);
    }

    public function testCategoryBreakdownRanksSpendAndAddsPercentages(): void
    {
        $this->expense($this->foodId, '300.00', '2026-05-04');
        $this->expense($this->transportId, '100.00', '2026-05-06');

        $breakdown = $this->summary('2026-05')['category_breakdown'];

        $this->assertCount(2, $breakdown);
        $this->assertSame('Food', $breakdown[0]['name']);
        $this->assertSame(30000, $breakdown[0]['total_cents']);
        $this->assertEquals(75.0, $breakdown[0]['percent']);
        $this->assertEquals(25.0, $breakdown[1]['percent']);
        $this->assertSame(1, $breakdown[1]['transaction_count']);
    }

    public function testTrendCoversSixMonthsIncludingEmptyOnes(): void
    {
        $this->income($this->salaryId, '1000.00', '2026-03-01');
        $this->expense($this->foodId, '400.00', '2026-05-02');

        $trend = $this->summary('2026-05')['trend'];

        $this->assertCount(6, $trend);
        $this->assertSame(['2025-12', '2026-01', '2026-02', '2026-03', '2026-04', '2026-05'], array_column($trend, 'month'));
        $this->assertSame(100000, $trend[3]['income_cents']);
        $this->assertSame(0, $trend[4]['income_cents']);
        $this->assertSame(40000, $trend[5]['expense_cents']);
        $this->assertSame(-40000, $trend[5]['net_cents']);
    }

    public function testBudgetProgressIsIncludedForTheRequestedMonth(): void
    {
        $this->post('/api/v1/budgets', [
            'category_id' => $this->foodId,
            'month' => '2026-05',
            'amount_limit' => '500.00',
        ], $this->token);
        $this->expense($this->foodId, '125.00', '2026-05-04');

        $budgets = $this->summary('2026-05')['budgets'];

        $this->assertCount(1, $budgets);
        $this->assertSame(12500, $budgets[0]['spent_cents']);
        $this->assertSame(37500, $budgets[0]['remaining_cents']);
        $this->assertEquals(25.0, $budgets[0]['percent_used']);
    }

    public function testDefaultsToTheCurrentMonth(): void
    {
        $response = $this->get('/api/v1/dashboard/summary', $this->token);

        $this->assertStatus(200, $response);
        $this->assertSame(date('Y-m'), $response->decoded()['data']['month']);
    }

    public function testSummaryNeverIncludesAnotherUsersMoney(): void
    {
        $intruder = $this->registerUser('intruder@example.test');
        $theirAccount = $this->createAccount($intruder['token'], 'Their Vault', 'bank', 999999.00);

        $this->assertSame(700000, $this->summary('2026-05')['net_worth_cents']);
        $this->assertSame(
            99999900,
            $this->summary('2026-05', $intruder['token'])['net_worth_cents']
        );
        $this->assertNotContains(
            'Their Vault',
            array_column($this->summary('2026-05')['accounts'], 'name')
        );
    }

    public function testRequiresAuthentication(): void
    {
        $this->assertStatus(401, $this->get('/api/v1/dashboard/summary'));
    }

    /** @return array<string, mixed> */
    private function summary(string $month, ?string $token = null): array
    {
        $response = $this->get("/api/v1/dashboard/summary?month={$month}", $token ?? $this->token);
        $this->assertStatus(200, $response);

        return $response->decoded()['data'];
    }

    private function expense(int $categoryId, string $amount, string $date): void
    {
        $response = $this->post('/api/v1/transactions', [
            'type' => 'expense',
            'account_id' => $this->checkingId,
            'category_id' => $categoryId,
            'amount' => $amount,
            'description' => 'spend',
            'transaction_date' => $date,
        ], $this->token);

        $this->assertStatus(201, $response);
    }

    private function income(int $categoryId, string $amount, string $date): void
    {
        $response = $this->post('/api/v1/transactions', [
            'type' => 'income',
            'account_id' => $this->checkingId,
            'category_id' => $categoryId,
            'amount' => $amount,
            'description' => 'earn',
            'transaction_date' => $date,
        ], $this->token);

        $this->assertStatus(201, $response);
    }
}
