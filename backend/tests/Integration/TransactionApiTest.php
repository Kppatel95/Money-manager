<?php

declare(strict_types=1);

namespace Tests\Integration;

use Tests\Support\ApiTestCase;

/**
 * The transaction lifecycle over HTTP, including the CSV export.
 */
final class TransactionApiTest extends ApiTestCase
{
    private string $token;
    private int $accountId;
    private int $savingsId;
    private int $foodId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->token = $this->registerUser()['token'];
        $this->accountId = $this->createAccount($this->token, 'Checking', 'bank', 500.00)['id'];
        $this->savingsId = $this->createAccount($this->token, 'Savings', 'savings')['id'];
        $this->foodId = $this->categoryId($this->token, 'Food');
    }

    public function testCreateReadUpdateDeleteAndTheBalanceThatFollows(): void
    {
        $created = $this->post('/api/v1/transactions', [
            'type' => 'expense',
            'account_id' => $this->accountId,
            'category_id' => $this->foodId,
            'amount' => '24.99',
            'description' => 'Dinner',
            'notes' => 'Split with Sam',
            'tags' => ['social'],
            'transaction_date' => '2026-05-12',
        ], $this->token);

        $this->assertStatus(201, $created);
        $transaction = $created->decoded()['data'];
        $this->assertSame(2499, $transaction['amount_cents']);
        $this->assertSame(['social'], $transaction['tags']);
        $this->assertSame('Checking', $transaction['account_name']);

        $this->assertSame(
            50000 - 2499,
            $this->get("/api/v1/accounts/{$this->accountId}/balance", $this->token)->decoded()['data']['balance_cents']
        );

        $fetched = $this->get("/api/v1/transactions/{$transaction['id']}", $this->token);
        $this->assertStatus(200, $fetched);
        $this->assertSame('Dinner', $fetched->decoded()['data']['description']);

        $updated = $this->put("/api/v1/transactions/{$transaction['id']}", ['amount' => '30.00'], $this->token);
        $this->assertStatus(200, $updated);
        $this->assertSame(3000, $updated->decoded()['data']['amount_cents']);
        $this->assertSame('Dinner', $updated->decoded()['data']['description'], 'untouched fields survive a partial update');

        $this->assertSame(
            50000 - 3000,
            $this->get("/api/v1/accounts/{$this->accountId}/balance", $this->token)->decoded()['data']['balance_cents']
        );

        $this->assertStatus(204, $this->delete("/api/v1/transactions/{$transaction['id']}", $this->token));
        $this->assertStatus(404, $this->get("/api/v1/transactions/{$transaction['id']}", $this->token));
        $this->assertSame(
            50000,
            $this->get("/api/v1/accounts/{$this->accountId}/balance", $this->token)->decoded()['data']['balance_cents']
        );
    }

    public function testValidationFailuresReportEveryBadField(): void
    {
        $response = $this->post('/api/v1/transactions', [
            'type' => 'donation',
            'amount' => 'lots',
            'transaction_date' => 'yesterday',
        ], $this->token);

        $this->assertStatus(422, $response);
        $details = $response->decoded()['error']['details'];
        $this->assertArrayHasKey('type', $details);
        $this->assertArrayHasKey('amount', $details);
        $this->assertArrayHasKey('transaction_date', $details);
        $this->assertArrayHasKey('account_id', $details);
    }

    public function testListPaginationMetadata(): void
    {
        for ($day = 1; $day <= 5; $day++) {
            $this->spend(sprintf('2026-05-%02d', $day), "Item {$day}");
        }

        $response = $this->get('/api/v1/transactions?page=2&per_page=2', $this->token);
        $this->assertStatus(200, $response);

        $body = $response->decoded();
        $this->assertCount(2, $body['data']);
        $this->assertSame(['page' => 2, 'per_page' => 2, 'total' => 5, 'total_pages' => 3], array_intersect_key(
            $body['meta'],
            array_flip(['page', 'per_page', 'total', 'total_pages'])
        ));
    }

    public function testListFiltersAndSearchOverHttp(): void
    {
        $this->spend('2026-05-01', 'Coffee beans');
        $this->spend('2026-06-01', 'Restaurant lunch');

        $this->assertCount(1, $this->get('/api/v1/transactions?search=lunch', $this->token)->decoded()['data']);
        $this->assertCount(
            1,
            $this->get('/api/v1/transactions?date_from=2026-05-01&date_to=2026-05-31', $this->token)->decoded()['data']
        );
        $this->assertCount(
            2,
            $this->get("/api/v1/transactions?account_id={$this->accountId}", $this->token)->decoded()['data']
        );
        $this->assertCount(0, $this->get('/api/v1/transactions?type=income', $this->token)->decoded()['data']);
    }

    public function testBadFilterValuesAreRejected(): void
    {
        $response = $this->get('/api/v1/transactions?type=refund', $this->token);

        $this->assertStatus(422, $response);
        $this->assertErrorCode('VALIDATION_ERROR', $response);
    }

    public function testExportReturnsCsvForTheFilteredSet(): void
    {
        $this->spend('2026-05-01', 'Coffee, beans');       // comma must be quoted
        $this->spend('2026-06-01', 'Restaurant lunch');

        $response = $this->get('/api/v1/transactions/export', $this->token);

        $this->assertStatus(200, $response);
        $this->assertStringContainsString('text/csv', $response->headers['Content-Type']);
        $this->assertStringContainsString('attachment; filename=', $response->headers['Content-Disposition']);

        $lines = array_values(array_filter(explode("\n", trim($response->body))));
        $this->assertStringContainsString('date,type,amount,account', $lines[0]);
        $this->assertCount(3, $lines, 'header plus two rows');
        $this->assertStringContainsString('"Coffee, beans"', $response->body);
        $this->assertStringContainsString('10.00', $response->body);

        $filtered = $this->get('/api/v1/transactions/export?date_from=2026-06-01', $this->token);
        $this->assertCount(2, array_values(array_filter(explode("\n", trim($filtered->body)))));
    }

    public function testExportIgnoresPaginationAndReturnsEverythingMatching(): void
    {
        for ($day = 1; $day <= 30; $day++) {
            $this->spend(sprintf('2026-05-%02d', $day), "Item {$day}");
        }

        $response = $this->get('/api/v1/transactions/export?per_page=5&page=1', $this->token);
        $lines = array_values(array_filter(explode("\n", trim($response->body))));

        $this->assertCount(31, $lines, 'header plus every matching row');
    }

    public function testTransfersOverHttpMoveMoneyBothWays(): void
    {
        $response = $this->post('/api/v1/transactions', [
            'type' => 'transfer',
            'account_id' => $this->accountId,
            'transfer_to_account_id' => $this->savingsId,
            'amount' => '150.00',
            'description' => 'To savings',
            'transaction_date' => '2026-05-14',
        ], $this->token);

        $this->assertStatus(201, $response);
        $this->assertNull($response->decoded()['data']['category_id']);
        $this->assertSame('Savings', $response->decoded()['data']['transfer_to_account_name']);

        $this->assertSame(35000, $this->balance($this->accountId));
        $this->assertSame(15000, $this->balance($this->savingsId));
    }

    public function testUnknownIdsAre404AndNonNumericIdsToo(): void
    {
        $this->assertStatus(404, $this->get('/api/v1/transactions/9999', $this->token));
        $this->assertStatus(404, $this->get('/api/v1/transactions/not-a-number', $this->token));
    }

    public function testWrongVerbOnAKnownPathIs405(): void
    {
        $response = $this->request('PUT', '/api/v1/transactions', [], $this->token);

        $this->assertStatus(405, $response);
        $this->assertErrorCode('METHOD_NOT_ALLOWED', $response);
    }

    public function testMalformedJsonIs400(): void
    {
        $response = $this->request('POST', '/api/v1/transactions', '{"type": "expense",', $this->token);

        $this->assertStatus(400, $response);
        $this->assertErrorCode('BAD_REQUEST', $response);
    }

    private function spend(string $date, string $description): void
    {
        $this->assertStatus(201, $this->post('/api/v1/transactions', [
            'type' => 'expense',
            'account_id' => $this->accountId,
            'category_id' => $this->foodId,
            'amount' => '10.00',
            'description' => $description,
            'transaction_date' => $date,
        ], $this->token));
    }

    private function balance(int $accountId): int
    {
        return $this->get("/api/v1/accounts/{$accountId}/balance", $this->token)->decoded()['data']['balance_cents'];
    }
}
