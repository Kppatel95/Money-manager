<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Exceptions\ConflictException;
use App\Exceptions\NotFoundException;
use App\Exceptions\ValidationException;
use App\Repositories\AccountRepository;
use App\Services\AccountService;
use Tests\Support\ServiceTestCase;

final class AccountServiceTest extends ServiceTestCase
{
    private AccountService $service;
    private int $userId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new AccountService(new AccountRepository($this->pdo));
        $this->userId = $this->createUser();
    }

    public function testCreatesAnAccountWithCentPrecision(): void
    {
        $account = $this->service->create($this->userId, [
            'name' => 'Everyday Checking',
            'type' => 'bank',
            'initial_balance' => '1250.75',
            'currency' => 'eur',
        ]);

        $this->assertSame('Everyday Checking', $account['name']);
        $this->assertSame('EUR', $account['currency']);
        $this->assertSame(125075, $account['initial_balance_cents']);
        $this->assertSame(1250.75, $account['balance']);
        $this->assertFalse($account['archived']);
    }

    public function testAllowsANegativeOpeningBalance(): void
    {
        $account = $this->service->create($this->userId, [
            'name' => 'Credit Card',
            'type' => 'card',
            'initial_balance' => '-320.10',
        ]);

        $this->assertSame(-32010, $account['balance_cents']);
    }

    public function testRejectsUnknownAccountTypes(): void
    {
        $this->expectException(ValidationException::class);
        $this->service->create($this->userId, ['name' => 'Vault', 'type' => 'crypto']);
    }

    public function testRejectsDuplicateNamesForTheSameUser(): void
    {
        $this->service->create($this->userId, ['name' => 'Wallet', 'type' => 'cash']);

        $this->expectException(ConflictException::class);
        $this->service->create($this->userId, ['name' => 'wallet', 'type' => 'cash']);
    }

    public function testDifferentUsersMayReuseAnAccountName(): void
    {
        $other = $this->createUser('other@example.test');

        $this->service->create($this->userId, ['name' => 'Wallet', 'type' => 'cash']);
        $second = $this->service->create($other, ['name' => 'Wallet', 'type' => 'cash']);

        $this->assertSame('Wallet', $second['name']);
    }

    public function testBalanceReflectsIncomeExpenseAndTransfers(): void
    {
        $checking = $this->service->create($this->userId, [
            'name' => 'Checking', 'type' => 'bank', 'initial_balance' => '100.00',
        ]);
        $savings = $this->service->create($this->userId, [
            'name' => 'Savings', 'type' => 'savings', 'initial_balance' => '0',
        ]);

        $this->insertTransaction($checking['id'], 'income', 50000);   // +500.00
        $this->insertTransaction($checking['id'], 'expense', 12550);  // -125.50
        $this->insertTransaction($checking['id'], 'transfer', 20000, $savings['id']);

        $breakdown = $this->service->balance($this->userId, $checking['id']);

        $this->assertSame(10000, $breakdown['initial_balance_cents']);
        $this->assertSame(50000, $breakdown['income_cents']);
        $this->assertSame(12550, $breakdown['expense_cents']);
        $this->assertSame(20000, $breakdown['transfer_out_cents']);
        $this->assertSame(10000 + 50000 - 12550 - 20000, $breakdown['balance_cents']);
        $this->assertSame(274.5, $breakdown['balance']);

        $this->assertSame(20000, $this->service->balance($this->userId, $savings['id'])['balance_cents']);
    }

    public function testDeletingAnUnusedAccountRemovesIt(): void
    {
        $account = $this->service->create($this->userId, ['name' => 'Scratch', 'type' => 'cash']);

        $result = $this->service->delete($this->userId, $account['id']);

        $this->assertSame('deleted', $result['action']);
        $this->assertSame([], $this->service->list($this->userId));
    }

    public function testDeletingAnAccountWithHistoryArchivesItInstead(): void
    {
        $account = $this->service->create($this->userId, ['name' => 'Checking', 'type' => 'bank']);
        $this->insertTransaction($account['id'], 'expense', 999);

        $result = $this->service->delete($this->userId, $account['id']);

        $this->assertSame('archived', $result['action']);
        $this->assertSame([], $this->service->list($this->userId));
        $this->assertTrue($this->service->list($this->userId, includeArchived: true)[0]['archived']);
    }

    public function testAnotherUsersAccountIsReportedAsMissing(): void
    {
        $other = $this->createUser('other@example.test');
        $theirs = $this->service->create($other, ['name' => 'Theirs', 'type' => 'cash']);

        $this->expectException(NotFoundException::class);
        $this->service->get($this->userId, $theirs['id']);
    }

    private function insertTransaction(int $accountId, string $type, int $amount, ?int $transferTo = null): void
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO transactions (user_id, account_id, type, amount, transfer_to_account_id, description, transaction_date)
             VALUES (:user_id, :account_id, :type, :amount, :transfer_to, :description, :date)'
        );
        $stmt->execute([
            'user_id' => $this->userId,
            'account_id' => $accountId,
            'type' => $type,
            'amount' => $amount,
            'transfer_to' => $transferTo,
            'description' => 'test',
            'date' => date('Y-m-d'),
        ]);
    }
}
