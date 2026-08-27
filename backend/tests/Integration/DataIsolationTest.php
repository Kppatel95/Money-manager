<?php

declare(strict_types=1);

namespace Tests\Integration;

use Tests\Support\ApiTestCase;

/**
 * One user must never be able to see, edit or delete another user's data --
 * and the API must not even confirm that a foreign id exists, which is why
 * every one of these is a 404 rather than a 403.
 *
 * This is the test that would catch a forgotten "AND user_id = ?".
 */
final class DataIsolationTest extends ApiTestCase
{
    private string $ada;
    private string $eve;

    /** @var array<string, int> ids of resources that belong to Ada */
    private array $adaIds = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->ada = $this->registerUser('ada@example.test')['token'];
        $this->eve = $this->registerUser('eve@example.test')['token'];

        $account = $this->createAccount($this->ada, 'Ada Checking', 'bank', 1000.00);
        $this->adaIds['account'] = $account['id'];

        $category = $this->post('/api/v1/categories', [
            'name' => 'Ada Only',
            'type' => 'expense',
        ], $this->ada)->decoded()['data'];
        $this->adaIds['category'] = $category['id'];

        $this->adaIds['transaction'] = $this->post('/api/v1/transactions', [
            'type' => 'expense',
            'account_id' => $account['id'],
            'category_id' => $category['id'],
            'amount' => '99.99',
            'description' => 'Ada secret spend',
            'transaction_date' => '2026-05-05',
        ], $this->ada)->decoded()['data']['id'];

        $this->adaIds['budget'] = $this->post('/api/v1/budgets', [
            'category_id' => $category['id'],
            'month' => '2026-05',
            'amount_limit' => '500.00',
        ], $this->ada)->decoded()['data']['id'];

        $this->adaIds['recurring'] = $this->post('/api/v1/recurring-transactions', [
            'type' => 'expense',
            'account_id' => $account['id'],
            'category_id' => $category['id'],
            'amount' => '20.00',
            'description' => 'Ada subscription',
            'frequency' => 'monthly',
            'next_run_date' => '2099-01-01',
        ], $this->ada)->decoded()['data']['id'];
    }

    public function testEveSeesNoneOfAdasCollections(): void
    {
        $this->assertSame([], $this->get('/api/v1/accounts', $this->eve)->decoded()['data']);
        $this->assertSame([], $this->get('/api/v1/transactions', $this->eve)->decoded()['data']);
        $this->assertSame([], $this->get('/api/v1/budgets?month=2026-05', $this->eve)->decoded()['data']);
        $this->assertSame([], $this->get('/api/v1/recurring-transactions', $this->eve)->decoded()['data']);

        $categoryNames = array_column($this->get('/api/v1/categories', $this->eve)->decoded()['data'], 'name');
        $this->assertNotContains('Ada Only', $categoryNames);
        $this->assertContains('Food', $categoryNames, 'system categories are still shared');
    }

    public function testEveCannotReadAdasResources(): void
    {
        $this->assertStatus(404, $this->get("/api/v1/accounts/{$this->adaIds['account']}", $this->eve));
        $this->assertStatus(404, $this->get("/api/v1/accounts/{$this->adaIds['account']}/balance", $this->eve));
        $this->assertStatus(404, $this->get("/api/v1/transactions/{$this->adaIds['transaction']}", $this->eve));
    }

    public function testEveCannotModifyOrDeleteAdasResources(): void
    {
        $cases = [
            ['PUT', "/api/v1/accounts/{$this->adaIds['account']}", ['name' => 'Mine now']],
            ['DELETE', "/api/v1/accounts/{$this->adaIds['account']}", []],
            ['PUT', "/api/v1/categories/{$this->adaIds['category']}", ['name' => 'Mine now']],
            ['DELETE', "/api/v1/categories/{$this->adaIds['category']}", []],
            ['PUT', "/api/v1/transactions/{$this->adaIds['transaction']}", ['amount' => '1.00']],
            ['DELETE', "/api/v1/transactions/{$this->adaIds['transaction']}", []],
            ['PUT', "/api/v1/budgets/{$this->adaIds['budget']}", ['amount_limit' => '1.00']],
            ['DELETE', "/api/v1/budgets/{$this->adaIds['budget']}", []],
            ['PUT', "/api/v1/recurring-transactions/{$this->adaIds['recurring']}", ['amount' => '1.00']],
            ['DELETE', "/api/v1/recurring-transactions/{$this->adaIds['recurring']}", []],
        ];

        foreach ($cases as [$method, $path, $body]) {
            $response = $this->request($method, $path, $body, $this->eve);

            $this->assertStatus(404, $response, "{$method} {$path}");
            $this->assertErrorCode('NOT_FOUND', $response);
        }
    }

    public function testEveCannotPostTransactionsIntoAdasAccount(): void
    {
        $eveCategory = $this->categoryId($this->eve, 'Food');

        $response = $this->post('/api/v1/transactions', [
            'type' => 'expense',
            'account_id' => $this->adaIds['account'],
            'category_id' => $eveCategory,
            'amount' => '5.00',
            'transaction_date' => '2026-05-05',
        ], $this->eve);

        $this->assertStatus(404, $response);
    }

    public function testEveCannotBudgetAgainstAdasPrivateCategory(): void
    {
        $response = $this->post('/api/v1/budgets', [
            'category_id' => $this->adaIds['category'],
            'month' => '2026-05',
            'amount_limit' => '100.00',
        ], $this->eve);

        $this->assertStatus(404, $response);
    }

    public function testEveCannotTransferIntoAdasAccount(): void
    {
        $eveAccount = $this->createAccount($this->eve, 'Eve Wallet', 'cash', 100.00)['id'];

        $response = $this->post('/api/v1/transactions', [
            'type' => 'transfer',
            'account_id' => $eveAccount,
            'transfer_to_account_id' => $this->adaIds['account'],
            'amount' => '50.00',
            'transaction_date' => '2026-05-06',
        ], $this->eve);

        $this->assertStatus(404, $response);
    }

    public function testAdasDataIsUntouchedAfterAllOfThat(): void
    {
        $this->testEveCannotModifyOrDeleteAdasResources();

        $this->assertStatus(200, $this->get("/api/v1/accounts/{$this->adaIds['account']}", $this->ada));
        $this->assertSame(
            100000 - 9999,
            $this->get("/api/v1/accounts/{$this->adaIds['account']}/balance", $this->ada)->decoded()['data']['balance_cents']
        );
        $this->assertCount(1, $this->get('/api/v1/transactions', $this->ada)->decoded()['data']);
        $this->assertCount(1, $this->get('/api/v1/budgets?month=2026-05', $this->ada)->decoded()['data']);
    }

    public function testExportOnlyContainsTheCallersRows(): void
    {
        $eveAccount = $this->createAccount($this->eve, 'Eve Wallet', 'cash', 100.00)['id'];
        $this->post('/api/v1/transactions', [
            'type' => 'expense',
            'account_id' => $eveAccount,
            'category_id' => $this->categoryId($this->eve, 'Food'),
            'amount' => '3.50',
            'description' => 'Eve coffee',
            'transaction_date' => '2026-05-05',
        ], $this->eve);

        $eveExport = $this->get('/api/v1/transactions/export', $this->eve)->body;

        $this->assertStringContainsString('Eve coffee', $eveExport);
        $this->assertStringNotContainsString('Ada secret spend', $eveExport);
    }
}
