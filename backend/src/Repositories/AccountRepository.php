<?php

declare(strict_types=1);

namespace App\Repositories;

use PDO;

/**
 * Accounts, plus the SQL that derives their balances.
 *
 * Balances are never stored. initial_balance plus the signed sum of the
 * account's transactions is the single source of truth, so there is no
 * running total to drift out of sync when a transaction is edited, deleted or
 * back-dated. SQLite sums integers exactly, so the arithmetic is exact.
 */
final class AccountRepository
{
    /**
     * Signed contribution of each transaction to the balance of the account it
     * points at: income adds, expense subtracts, a transfer leaves the source
     * account (the destination side is added separately).
     */
    private const OUTGOING_SUM = "COALESCE((
        SELECT SUM(CASE t.type
            WHEN 'income' THEN t.amount
            ELSE -t.amount
        END)
        FROM transactions t WHERE t.account_id = a.id
    ), 0)";

    private const INCOMING_SUM = "COALESCE((
        SELECT SUM(t.amount) FROM transactions t
        WHERE t.transfer_to_account_id = a.id AND t.type = 'transfer'
    ), 0)";

    public function __construct(private readonly PDO $pdo)
    {
    }

    /** @return array<int, array<string, mixed>> */
    public function allForUser(int $userId, bool $includeArchived = false): array
    {
        $sql = 'SELECT a.*, ' . self::OUTGOING_SUM . ' + ' . self::INCOMING_SUM . ' + a.initial_balance AS balance
                FROM accounts a WHERE a.user_id = :user_id';

        if (!$includeArchived) {
            $sql .= ' AND a.archived = 0';
        }

        $sql .= ' ORDER BY a.archived ASC, a.name COLLATE NOCASE ASC';

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute(['user_id' => $userId]);

        return $stmt->fetchAll();
    }

    /** @return array<string, mixed>|null */
    public function findForUser(int $id, int $userId): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT a.*, ' . self::OUTGOING_SUM . ' + ' . self::INCOMING_SUM . ' + a.initial_balance AS balance
             FROM accounts a WHERE a.id = :id AND a.user_id = :user_id'
        );
        $stmt->execute(['id' => $id, 'user_id' => $userId]);
        $row = $stmt->fetch();

        return $row === false ? null : $row;
    }

    /**
     * Movement breakdown behind an account's balance.
     *
     * @return array{initial: int, income: int, expense: int, transfer_in: int, transfer_out: int, balance: int}
     */
    public function balanceBreakdown(int $accountId): array
    {
        $stmt = $this->pdo->prepare(
            "SELECT
                (SELECT initial_balance FROM accounts WHERE id = :id) AS initial,
                COALESCE((SELECT SUM(amount) FROM transactions WHERE account_id = :id AND type = 'income'), 0) AS income,
                COALESCE((SELECT SUM(amount) FROM transactions WHERE account_id = :id AND type = 'expense'), 0) AS expense,
                COALESCE((SELECT SUM(amount) FROM transactions WHERE transfer_to_account_id = :id AND type = 'transfer'), 0) AS transfer_in,
                COALESCE((SELECT SUM(amount) FROM transactions WHERE account_id = :id AND type = 'transfer'), 0) AS transfer_out"
        );
        $stmt->execute(['id' => $accountId]);
        $row = $stmt->fetch() ?: [];

        $initial = (int) ($row['initial'] ?? 0);
        $income = (int) ($row['income'] ?? 0);
        $expense = (int) ($row['expense'] ?? 0);
        $in = (int) ($row['transfer_in'] ?? 0);
        $out = (int) ($row['transfer_out'] ?? 0);

        return [
            'initial' => $initial,
            'income' => $income,
            'expense' => $expense,
            'transfer_in' => $in,
            'transfer_out' => $out,
            'balance' => $initial + $income - $expense + $in - $out,
        ];
    }

    public function findByName(int $userId, string $name, ?int $exceptId = null): ?array
    {
        $sql = 'SELECT * FROM accounts WHERE user_id = :user_id AND name = :name COLLATE NOCASE';
        $params = ['user_id' => $userId, 'name' => $name];

        if ($exceptId !== null) {
            $sql .= ' AND id != :except';
            $params['except'] = $exceptId;
        }

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        $row = $stmt->fetch();

        return $row === false ? null : $row;
    }

    /** @param array{name: string, type: string, initial_balance: int, currency: string} $data */
    public function create(int $userId, array $data): array
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO accounts (user_id, name, type, initial_balance, currency)
             VALUES (:user_id, :name, :type, :initial_balance, :currency)'
        );
        $stmt->execute([
            'user_id' => $userId,
            'name' => $data['name'],
            'type' => $data['type'],
            'initial_balance' => $data['initial_balance'],
            'currency' => $data['currency'],
        ]);

        /** @var array<string, mixed> */
        return $this->findForUser((int) $this->pdo->lastInsertId(), $userId);
    }

    /** @param array<string, mixed> $fields */
    public function update(int $id, int $userId, array $fields): ?array
    {
        if ($fields === []) {
            return $this->findForUser($id, $userId);
        }

        $assignments = implode(', ', array_map(static fn (string $c): string => "{$c} = :{$c}", array_keys($fields)));
        $stmt = $this->pdo->prepare("UPDATE accounts SET {$assignments} WHERE id = :id AND user_id = :user_id");
        $stmt->execute($fields + ['id' => $id, 'user_id' => $userId]);

        return $this->findForUser($id, $userId);
    }

    public function delete(int $id, int $userId): bool
    {
        $stmt = $this->pdo->prepare('DELETE FROM accounts WHERE id = :id AND user_id = :user_id');
        $stmt->execute(['id' => $id, 'user_id' => $userId]);

        return $stmt->rowCount() > 0;
    }

    public function transactionCount(int $accountId): int
    {
        $stmt = $this->pdo->prepare(
            'SELECT COUNT(*) FROM transactions WHERE account_id = :id OR transfer_to_account_id = :id'
        );
        $stmt->execute(['id' => $accountId]);

        return (int) $stmt->fetchColumn();
    }
}
