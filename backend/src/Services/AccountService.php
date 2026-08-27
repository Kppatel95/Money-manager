<?php

declare(strict_types=1);

namespace App\Services;

use App\Exceptions\ConflictException;
use App\Exceptions\NotFoundException;
use App\Repositories\AccountRepository;
use App\Support\Money;
use App\Validation\Validator;

/**
 * Business rules for the money containers a user tracks.
 *
 * Deleting is soft when it would rewrite history: an account with
 * transactions attached is archived (hidden from the default list, still
 * referenced by its ledger rows) rather than deleted, because deleting it
 * would silently change past balances. An account nobody ever used is just a
 * typo, so that one is really deleted.
 */
final class AccountService
{
    public const TYPES = ['cash', 'bank', 'card', 'wallet', 'savings'];

    public function __construct(private readonly AccountRepository $accounts)
    {
    }

    /** @return array<int, array<string, mixed>> */
    public function list(int $userId, bool $includeArchived = false): array
    {
        return array_map([$this, 'present'], $this->accounts->allForUser($userId, $includeArchived));
    }

    /** @return array<string, mixed> */
    public function get(int $userId, int $accountId): array
    {
        return $this->present($this->requireAccount($userId, $accountId));
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function create(int $userId, array $payload): array
    {
        $v = new Validator($payload);
        $name = $v->requiredString('name', 80);
        $type = $v->requiredEnum('type', self::TYPES);
        $initial = $v->signedAmountCents('initial_balance');
        $currency = $this->currency($v, $payload);
        $v->validate();

        if ($this->accounts->findByName($userId, $name) !== null) {
            throw new ConflictException('An account with that name already exists.');
        }

        return $this->present($this->accounts->create($userId, [
            'name' => $name,
            'type' => $type,
            'initial_balance' => $initial,
            'currency' => $currency,
        ]));
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function update(int $userId, int $accountId, array $payload): array
    {
        $this->requireAccount($userId, $accountId);

        $v = new Validator($payload);
        $fields = [];

        if ($v->has('name')) {
            $fields['name'] = $v->requiredString('name', 80);
        }
        if ($v->has('type')) {
            $fields['type'] = $v->requiredEnum('type', self::TYPES);
        }
        if ($v->has('initial_balance')) {
            $fields['initial_balance'] = $v->signedAmountCents('initial_balance');
        }
        if ($v->has('currency')) {
            $fields['currency'] = $this->currency($v, $payload);
        }
        if ($v->has('archived')) {
            $fields['archived'] = (int) ($v->optionalBool('archived') ?? false);
        }

        $v->validate();

        if (isset($fields['name']) && $this->accounts->findByName($userId, $fields['name'], $accountId) !== null) {
            throw new ConflictException('An account with that name already exists.');
        }

        /** @var array<string, mixed> */
        return $this->present($this->accounts->update($accountId, $userId, $fields));
    }

    /**
     * Archives the account if it carries transactions, otherwise deletes it.
     *
     * @return array{id: int, action: string}
     */
    public function delete(int $userId, int $accountId): array
    {
        $this->requireAccount($userId, $accountId);

        if ($this->accounts->transactionCount($accountId) > 0) {
            $this->accounts->update($accountId, $userId, ['archived' => 1]);

            return ['id' => $accountId, 'action' => 'archived'];
        }

        $this->accounts->delete($accountId, $userId);

        return ['id' => $accountId, 'action' => 'deleted'];
    }

    /** @return array<string, mixed> */
    public function balance(int $userId, int $accountId): array
    {
        $account = $this->requireAccount($userId, $accountId);
        $breakdown = $this->accounts->balanceBreakdown($accountId);

        return [
            'account_id' => (int) $account['id'],
            'name' => $account['name'],
            'currency' => $account['currency'],
            'initial_balance_cents' => $breakdown['initial'],
            'income_cents' => $breakdown['income'],
            'expense_cents' => $breakdown['expense'],
            'transfer_in_cents' => $breakdown['transfer_in'],
            'transfer_out_cents' => $breakdown['transfer_out'],
            'balance_cents' => $breakdown['balance'],
            'balance' => Money::toMajor($breakdown['balance']),
        ];
    }

    /**
     * Every read goes through here, so a row belonging to somebody else is
     * indistinguishable from one that does not exist.
     *
     * @return array<string, mixed>
     */
    public function requireAccount(int $userId, int $accountId): array
    {
        $account = $this->accounts->findForUser($accountId, $userId);

        if ($account === null) {
            throw NotFoundException::for('Account');
        }

        return $account;
    }

    /** @param array<string, mixed> $account */
    public function present(array $account): array
    {
        $balance = (int) ($account['balance'] ?? $account['initial_balance']);

        return [
            'id' => (int) $account['id'],
            'name' => $account['name'],
            'type' => $account['type'],
            'currency' => $account['currency'],
            'initial_balance_cents' => (int) $account['initial_balance'],
            'initial_balance' => Money::toMajor((int) $account['initial_balance']),
            'balance_cents' => $balance,
            'balance' => Money::toMajor($balance),
            'archived' => (bool) $account['archived'],
            'created_at' => $account['created_at'],
        ];
    }

    /** @param array<string, mixed> $payload */
    private function currency(Validator $v, array $payload): string
    {
        if (!array_key_exists('currency', $payload) || $payload['currency'] === null) {
            return 'USD';
        }

        $currency = strtoupper((string) $v->optionalString('currency', 3));

        if (preg_match('/^[A-Z]{3}$/', $currency) !== 1) {
            $v->add('currency', 'Currency must be a 3-letter code such as USD.');
            return 'USD';
        }

        return $currency;
    }
}
