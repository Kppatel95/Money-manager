<?php

declare(strict_types=1);

namespace Tests\Integration;

use Tests\Support\ApiTestCase;

/**
 * HTTP-level coverage for the account, category, budget and recurring
 * endpoints: status codes, envelopes and the auto-run hook.
 */
final class ResourceEndpointsTest extends ApiTestCase
{
    private string $token;

    protected function setUp(): void
    {
        parent::setUp();
        $this->token = $this->registerUser()['token'];
    }

    public function testAccountLifecycleOverHttp(): void
    {
        $created = $this->post('/api/v1/accounts', [
            'name' => 'Travel Card',
            'type' => 'card',
            'initial_balance' => '250.50',
            'currency' => 'gbp',
        ], $this->token);

        $this->assertStatus(201, $created);
        $account = $created->decoded()['data'];
        $this->assertSame('GBP', $account['currency']);
        $this->assertSame(25050, $account['balance_cents']);

        $this->assertStatus(200, $this->put("/api/v1/accounts/{$account['id']}", ['name' => 'Travel'], $this->token));
        $this->assertSame('Travel', $this->get("/api/v1/accounts/{$account['id']}", $this->token)->decoded()['data']['name']);

        $deleted = $this->delete("/api/v1/accounts/{$account['id']}", $this->token);
        $this->assertStatus(200, $deleted);
        $this->assertSame('deleted', $deleted->decoded()['data']['action']);
        $this->assertSame([], $this->get('/api/v1/accounts', $this->token)->decoded()['data']);
    }

    public function testAnAccountWithHistoryIsArchivedAndCanBeListedAgain(): void
    {
        $account = $this->createAccount($this->token, 'Checking', 'bank', 100.00);

        $this->post('/api/v1/transactions', [
            'type' => 'expense',
            'account_id' => $account['id'],
            'category_id' => $this->categoryId($this->token, 'Food'),
            'amount' => '10.00',
            'transaction_date' => '2026-05-01',
        ], $this->token);

        $deleted = $this->delete("/api/v1/accounts/{$account['id']}", $this->token);
        $this->assertSame('archived', $deleted->decoded()['data']['action']);

        $this->assertCount(0, $this->get('/api/v1/accounts', $this->token)->decoded()['data']);
        $this->assertCount(1, $this->get('/api/v1/accounts?include_archived=true', $this->token)->decoded()['data']);

        // The ledger entry survives archiving.
        $this->assertCount(1, $this->get('/api/v1/transactions', $this->token)->decoded()['data']);
    }

    public function testDuplicateAccountNameIs409(): void
    {
        $this->createAccount($this->token, 'Wallet', 'cash');

        $response = $this->post('/api/v1/accounts', ['name' => 'Wallet', 'type' => 'cash'], $this->token);

        $this->assertStatus(409, $response);
        $this->assertErrorCode('CONFLICT', $response);
    }

    public function testCategoriesListSystemDefaultsAndAllowUserOnes(): void
    {
        $list = $this->get('/api/v1/categories', $this->token)->decoded()['data'];

        $this->assertNotEmpty($list);
        $this->assertTrue($list[0]['is_system']);

        $created = $this->post('/api/v1/categories', [
            'name' => 'Hobbies',
            'type' => 'expense',
            'icon' => 'X',
            'color' => '#123456',
        ], $this->token);

        $this->assertStatus(201, $created);
        $this->assertFalse($created->decoded()['data']['is_system']);

        $this->assertStatus(204, $this->delete("/api/v1/categories/{$created->decoded()['data']['id']}", $this->token));
    }

    public function testSystemCategoriesCannotBeChangedAndReport403(): void
    {
        $systemId = $this->categoryId($this->token, 'Food');

        $update = $this->put("/api/v1/categories/{$systemId}", ['name' => 'Groceries'], $this->token);
        $this->assertStatus(403, $update);
        $this->assertErrorCode('FORBIDDEN', $update);

        $this->assertStatus(403, $this->delete("/api/v1/categories/{$systemId}", $this->token));
    }

    public function testCategoryFilterByType(): void
    {
        $income = $this->get('/api/v1/categories?type=income', $this->token)->decoded()['data'];

        $this->assertNotEmpty($income);
        $this->assertSame(['income'], array_values(array_unique(array_column($income, 'type'))));
    }

    public function testBudgetEndpointsReturnSpendAndRejectDuplicates(): void
    {
        $account = $this->createAccount($this->token, 'Checking', 'bank', 1000.00);
        $foodId = $this->categoryId($this->token, 'Food');

        $created = $this->post('/api/v1/budgets', [
            'category_id' => $foodId,
            'month' => '2026-07',
            'amount_limit' => '300.00',
        ], $this->token);
        $this->assertStatus(201, $created);

        $this->post('/api/v1/transactions', [
            'type' => 'expense',
            'account_id' => $account['id'],
            'category_id' => $foodId,
            'amount' => '45.00',
            'transaction_date' => '2026-07-08',
        ], $this->token);

        $list = $this->get('/api/v1/budgets?month=2026-07', $this->token);
        $this->assertStatus(200, $list);
        $this->assertSame(4500, $list->decoded()['data'][0]['spent_cents']);
        $this->assertSame(25500, $list->decoded()['data'][0]['remaining_cents']);
        $this->assertSame('2026-07', $list->decoded()['meta']['month']);

        $duplicate = $this->post('/api/v1/budgets', [
            'category_id' => $foodId,
            'month' => '2026-07',
            'amount_limit' => '400.00',
        ], $this->token);
        $this->assertStatus(409, $duplicate);

        $this->assertStatus(204, $this->delete("/api/v1/budgets/{$created->decoded()['data']['id']}", $this->token));
        $this->assertSame([], $this->get('/api/v1/budgets?month=2026-07', $this->token)->decoded()['data']);
    }

    public function testRecurringSchedulesRunAutomaticallyOnTheNextRequest(): void
    {
        $account = $this->createAccount($this->token, 'Checking', 'bank', 1000.00);

        $created = $this->post('/api/v1/recurring-transactions', [
            'type' => 'expense',
            'account_id' => $account['id'],
            'category_id' => $this->categoryId($this->token, 'Bills'),
            'amount' => '75.00',
            'description' => 'Internet',
            'frequency' => 'monthly',
            'next_run_date' => date('Y-m-d', strtotime('-1 day')),
        ], $this->token);

        $this->assertStatus(201, $created);
        $this->assertTrue($created->decoded()['data']['active']);

        // A fresh Application instance stands in for the next HTTP request;
        // the auth middleware catches the schedule up before handling it.
        $this->app = \App\Http\Application::boot($this->pdo, \App\Support\Logger::null());
        $transactions = $this->get('/api/v1/transactions', $this->token)->decoded()['data'];

        $this->assertCount(1, $transactions);
        $this->assertSame('Internet', $transactions[0]['description']);
        $this->assertSame(['recurring'], $transactions[0]['tags']);

        $schedule = $this->get('/api/v1/recurring-transactions', $this->token)->decoded()['data'][0];
        $this->assertGreaterThan(date('Y-m-d'), $schedule['next_run_date']);
    }

    public function testRecurringScheduleCanBePausedAndDeleted(): void
    {
        $account = $this->createAccount($this->token, 'Checking', 'bank', 1000.00);

        $created = $this->post('/api/v1/recurring-transactions', [
            'type' => 'income',
            'account_id' => $account['id'],
            'category_id' => $this->categoryId($this->token, 'Salary'),
            'amount' => '2500.00',
            'description' => 'Salary',
            'frequency' => 'monthly',
            'next_run_date' => '2099-01-01',
        ], $this->token);

        $id = $created->decoded()['data']['id'];

        $paused = $this->put("/api/v1/recurring-transactions/{$id}", ['active' => false], $this->token);
        $this->assertStatus(200, $paused);
        $this->assertFalse($paused->decoded()['data']['active']);

        $this->assertStatus(204, $this->delete("/api/v1/recurring-transactions/{$id}", $this->token));
        $this->assertSame([], $this->get('/api/v1/recurring-transactions', $this->token)->decoded()['data']);
    }

    public function testUnknownRoutesReturnTheStandardErrorEnvelope(): void
    {
        $response = $this->get('/api/v1/nope', $this->token);

        $this->assertStatus(404, $response);
        $this->assertSame(
            ['code', 'message'],
            array_keys($response->decoded()['error'])
        );
    }
}
