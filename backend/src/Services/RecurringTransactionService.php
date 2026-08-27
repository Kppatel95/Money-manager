<?php

declare(strict_types=1);

namespace App\Services;

use App\Exceptions\NotFoundException;
use App\Exceptions\ValidationException;
use App\Repositories\RecurringTransactionRepository;
use App\Repositories\TransactionRepository;
use App\Support\Logger;
use App\Support\Money;
use App\Validation\Validator;
use DateTimeImmutable;

/**
 * Standing orders: rent on the 1st, a salary every month, a coffee
 * subscription every week.
 *
 * runDue() materialises every schedule whose next_run_date has arrived into a
 * real transaction and advances the schedule. It catches up: a schedule that
 * has not run for three months produces the three missed transactions, so
 * ledger history is the same whether the app was opened daily or once a
 * quarter.
 */
final class RecurringTransactionService
{
    public const FREQUENCIES = ['daily', 'weekly', 'monthly'];
    public const TYPES = ['income', 'expense'];

    /** Safety valve so a schedule dated years back cannot spin forever. */
    private const MAX_CATCHUP_RUNS = 400;

    public function __construct(
        private readonly RecurringTransactionRepository $recurring,
        private readonly TransactionRepository $transactions,
        private readonly AccountService $accounts,
        private readonly CategoryService $categories,
        private readonly Logger $logger = new Logger(null)
    ) {
    }

    /** @return array<int, array<string, mixed>> */
    public function list(int $userId): array
    {
        return array_map([$this, 'present'], $this->recurring->allForUser($userId));
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function create(int $userId, array $payload): array
    {
        $data = $this->validate($userId, $payload);

        return $this->present($this->recurring->create($userId, $data));
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function update(int $userId, int $id, array $payload): array
    {
        $existing = $this->requireSchedule($userId, $id);

        $merged = [
            'type' => $existing['type'],
            'account_id' => (int) $existing['account_id'],
            'category_id' => $existing['category_id'] === null ? null : (int) $existing['category_id'],
            'amount' => Money::toMajor((int) $existing['amount']),
            'description' => $existing['description'],
            'frequency' => $existing['frequency'],
            'next_run_date' => $existing['next_run_date'],
            'active' => (bool) $existing['active'],
        ];

        $merged = array_merge($merged, array_intersect_key($payload, $merged));

        /** @var array<string, mixed> */
        return $this->present($this->recurring->update($id, $userId, $this->validate($userId, $merged)));
    }

    public function delete(int $userId, int $id): void
    {
        $this->requireSchedule($userId, $id);
        $this->recurring->delete($id, $userId);
    }

    /**
     * Creates the transactions any due schedule owes and moves it forward.
     *
     * @return array<int, array<string, mixed>> the transactions that were created
     */
    public function runDue(int $userId, ?string $today = null): array
    {
        $today ??= date('Y-m-d');

        if (!$this->recurring->hasDue($userId, $today)) {
            return [];
        }

        $created = [];

        foreach ($this->recurring->due($userId, $today) as $schedule) {
            if ((int) ($schedule['account_archived'] ?? 0) === 1) {
                // The money has nowhere to go; pause instead of failing every
                // request from here on.
                $this->recurring->update((int) $schedule['id'], $userId, ['active' => 0]);
                $this->logger->warning('Paused recurring transaction {id}: its account is archived.', [
                    'id' => (int) $schedule['id'],
                    'user_id' => $userId,
                ]);
                continue;
            }

            $runDate = $schedule['next_run_date'];
            $runs = 0;

            while ($runDate <= $today && $runs < self::MAX_CATCHUP_RUNS) {
                $created[] = $this->transactions->create($userId, [
                    'account_id' => (int) $schedule['account_id'],
                    'category_id' => $schedule['category_id'] === null ? null : (int) $schedule['category_id'],
                    'type' => $schedule['type'],
                    'amount' => (int) $schedule['amount'],
                    'transfer_to_account_id' => null,
                    'description' => $schedule['description'],
                    'notes' => 'Created automatically from a recurring transaction.',
                    'tags' => json_encode(['recurring']),
                    'transaction_date' => $runDate,
                ]);

                $runDate = self::advance($runDate, $schedule['frequency']);
                $runs++;
            }

            $this->recurring->update((int) $schedule['id'], $userId, ['next_run_date' => $runDate]);

            $this->logger->info('Ran recurring transaction {id}: created {count} transaction(s), next run {next}.', [
                'id' => (int) $schedule['id'],
                'user_id' => $userId,
                'count' => $runs,
                'next' => $runDate,
            ]);
        }

        return $created;
    }

    /**
     * Next occurrence after $date.
     *
     * Monthly runs clamp to the end of a short month: 31 January advances to
     * 28 February, not to 3 March the way a naive "+1 month" would. The clamp
     * is not sticky -- once a schedule lands on the 28th it stays there --
     * which is the usual tradeoff for storing only the next date rather than
     * an anchor day.
     */
    public static function advance(string $date, string $frequency): string
    {
        $current = new DateTimeImmutable($date);

        return match ($frequency) {
            'daily' => $current->modify('+1 day')->format('Y-m-d'),
            'weekly' => $current->modify('+7 days')->format('Y-m-d'),
            'monthly' => self::addMonth($current),
            default => $current->modify('+1 day')->format('Y-m-d'),
        };
    }

    private static function addMonth(DateTimeImmutable $current): string
    {
        $day = (int) $current->format('j');
        $firstOfNextMonth = $current->modify('first day of next month');
        $daysInNextMonth = (int) $firstOfNextMonth->format('t');

        return $firstOfNextMonth->setDate(
            (int) $firstOfNextMonth->format('Y'),
            (int) $firstOfNextMonth->format('n'),
            min($day, $daysInNextMonth)
        )->format('Y-m-d');
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private function validate(int $userId, array $payload): array
    {
        $v = new Validator($payload);

        $type = $v->requiredEnum('type', self::TYPES);
        $accountId = $v->requiredId('account_id');
        $amount = $v->requiredAmountCents('amount');
        $frequency = $v->requiredEnum('frequency', self::FREQUENCIES);
        $nextRun = $v->requiredDate('next_run_date');
        $description = $v->optionalString('description', 255) ?? '';
        $categoryId = $v->optionalId('category_id');
        $active = $v->optionalBool('active') ?? true;

        if ($categoryId === null) {
            $v->add('category_id', 'Category is required.');
        }

        $v->validate();

        $account = $this->accounts->requireAccount($userId, $accountId);

        if ((bool) $account['archived']) {
            throw new ValidationException(['account_id' => 'That account is archived.']);
        }

        /** @var int $categoryId */
        $this->categories->requireVisible($userId, $categoryId, $type);

        return [
            'account_id' => $accountId,
            'category_id' => $categoryId,
            'type' => $type,
            'amount' => $amount,
            'description' => $description,
            'frequency' => $frequency,
            'next_run_date' => $nextRun,
            'active' => (int) $active,
        ];
    }

    /** @return array<string, mixed> */
    private function requireSchedule(int $userId, int $id): array
    {
        $schedule = $this->recurring->findForUser($id, $userId);

        if ($schedule === null) {
            throw NotFoundException::for('Recurring transaction');
        }

        return $schedule;
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    public function present(array $row): array
    {
        $amount = (int) $row['amount'];

        return [
            'id' => (int) $row['id'],
            'type' => $row['type'],
            'amount_cents' => $amount,
            'amount' => Money::toMajor($amount),
            'account_id' => (int) $row['account_id'],
            'account_name' => $row['account_name'] ?? null,
            'category_id' => $row['category_id'] === null ? null : (int) $row['category_id'],
            'category_name' => $row['category_name'] ?? null,
            'category_icon' => $row['category_icon'] ?? null,
            'category_color' => $row['category_color'] ?? null,
            'description' => $row['description'],
            'frequency' => $row['frequency'],
            'next_run_date' => $row['next_run_date'],
            'active' => (bool) $row['active'],
            'created_at' => $row['created_at'],
        ];
    }
}
