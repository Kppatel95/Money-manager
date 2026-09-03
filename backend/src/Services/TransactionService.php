<?php

declare(strict_types=1);

namespace App\Services;

use App\Exceptions\NotFoundException;
use App\Exceptions\ValidationException;
use App\Repositories\TransactionRepository;
use App\Support\Csv;
use App\Support\Money;
use App\Support\Request;
use App\Validation\Validator;

/**
 * The ledger. Creating, editing and deleting entries, and reading them back
 * with filters and pagination.
 *
 * Balances are not written anywhere by these methods: because an account's
 * balance is derived from its transactions, every write here changes the
 * affected balances as a side effect, and a back-dated or corrected entry can
 * never leave a stored total stale.
 *
 * Updates are merge-then-validate rather than patch-in-place. Changing a
 * transaction's type from expense to transfer changes which other fields are
 * legal, so the merged row is validated as a whole instead of field by field.
 */
final class TransactionService
{
    public const TYPES = ['income', 'expense', 'transfer'];

    public const DEFAULT_PER_PAGE = 25;
    public const MAX_PER_PAGE = 100;

    public function __construct(
        private readonly TransactionRepository $transactions,
        private readonly AccountService $accounts,
        private readonly CategoryService $categories,
        private readonly SubcategoryService $subcategories
    ) {
    }

    /**
     * @param array<string, mixed> $filters
     * @return array{data: array<int, array<string, mixed>>, meta: array<string, mixed>}
     */
    public function list(int $userId, array $filters, int $page = 1, int $perPage = self::DEFAULT_PER_PAGE): array
    {
        $filters = $this->normaliseFilters($filters);
        $page = max(1, $page);
        $perPage = min(self::MAX_PER_PAGE, max(1, $perPage));

        $result = $this->transactions->paginate($userId, $filters, $page, $perPage);
        $total = $result['total'];

        return [
            'data' => array_map([$this, 'present'], $result['rows']),
            'meta' => [
                'page' => $page,
                'per_page' => $perPage,
                'total' => $total,
                'total_pages' => $total === 0 ? 0 : (int) ceil($total / $perPage),
                'filters' => $filters,
            ],
        ];
    }

    /**
     * @param array<string, mixed> $filters
     * @return array<int, array<string, mixed>>
     */
    public function listAll(int $userId, array $filters): array
    {
        return array_map([$this, 'present'], $this->transactions->all($userId, $this->normaliseFilters($filters)));
    }

    /**
     * The filtered set as a CSV document -- same filters as the list
     * endpoint, no pagination, because an export that stopped at page one
     * would be a bug report waiting to happen.
     *
     * @param array<string, mixed> $filters
     */
    public function exportCsv(int $userId, array $filters): string
    {
        $rows = array_map(static fn (array $t): array => [
            $t['transaction_date'],
            $t['type'],
            Money::format($t['amount_cents']),
            $t['account_name'],
            $t['transfer_to_account_name'] ?? '',
            $t['category_name'] ?? '',
            $t['subcategory_name'] ?? '',
            $t['payment_method'] ?? '',
            $t['description'],
            $t['notes'] ?? '',
            implode(' ', $t['tags']),
        ], $this->listAll($userId, $filters));

        return Csv::build(
            [
                'date', 'type', 'amount', 'account', 'transfer_to_account',
                'category', 'subcategory', 'payment_method', 'description', 'notes', 'tags',
            ],
            $rows
        );
    }

    /** @return array<string, mixed> */
    public function get(int $userId, int $id): array
    {
        return $this->present($this->requireTransaction($userId, $id));
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function create(int $userId, array $payload): array
    {
        return $this->present($this->transactions->create($userId, $this->validate($userId, $payload)));
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function update(int $userId, int $id, array $payload): array
    {
        $existing = $this->requireTransaction($userId, $id);
        $merged = $this->merge($existing, $payload);

        /** @var array<string, mixed> */
        return $this->present($this->transactions->update($id, $userId, $this->validate($userId, $merged)));
    }

    public function delete(int $userId, int $id): void
    {
        $this->requireTransaction($userId, $id);
        $this->transactions->delete($id, $userId);
    }

    /**
     * Pulls the list filters out of a request. Kept here rather than in the
     * controller so the list and export endpoints cannot drift apart.
     *
     * @return array<string, mixed>
     */
    public static function filtersFromRequest(Request $request): array
    {
        return [
            'account_id' => $request->query('account_id'),
            'category_id' => $request->query('category_id'),
            'subcategory_id' => $request->query('subcategory_id'),
            'type' => $request->query('type'),
            'date_from' => $request->query('date_from'),
            'date_to' => $request->query('date_to'),
            'search' => $request->query('search'),
        ];
    }

    /**
     * Rejects nonsense filter values instead of quietly ignoring them, so a
     * client never gets a full list back when it asked for a narrow one.
     *
     * @param array<string, mixed> $filters
     * @return array<string, mixed>
     */
    private function normaliseFilters(array $filters): array
    {
        $clean = [];
        $errors = [];

        foreach (['account_id', 'category_id', 'subcategory_id'] as $key) {
            $value = $filters[$key] ?? null;
            if ($value !== null && $value !== '') {
                if (!ctype_digit((string) $value)) {
                    $errors[$key] = ucfirst(str_replace('_', ' ', $key)) . ' must be an integer.';
                    continue;
                }
                $clean[$key] = (int) $value;
            }
        }

        if (!empty($filters['type'])) {
            if (!in_array($filters['type'], self::TYPES, true)) {
                $errors['type'] = 'Type must be one of: ' . implode(', ', self::TYPES) . '.';
            } else {
                $clean['type'] = $filters['type'];
            }
        }

        foreach (['date_from', 'date_to'] as $key) {
            $value = $filters[$key] ?? null;
            if ($value !== null && $value !== '') {
                if (!is_string($value) || !Validator::isDate($value)) {
                    $errors[$key] = ucfirst(str_replace('_', ' ', $key)) . ' must be a date in YYYY-MM-DD format.';
                    continue;
                }
                $clean[$key] = $value;
            }
        }

        if (!empty($filters['search']) && is_string($filters['search'])) {
            $clean['search'] = mb_substr(trim($filters['search']), 0, 100);
        }

        if ($errors !== []) {
            throw new ValidationException($errors);
        }

        return $clean;
    }

    /**
     * Validates a complete transaction payload and returns the row to persist.
     *
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private function validate(int $userId, array $payload): array
    {
        $v = new Validator($payload);

        $type = $v->requiredEnum('type', self::TYPES);
        $accountId = $v->requiredId('account_id');
        $amount = $v->requiredAmountCents('amount');
        $date = $v->requiredDate('transaction_date');
        $description = $v->optionalString('description', 255) ?? '';
        $notes = $v->optionalString('notes', 2000);
        $tags = $v->optionalTags();
        $categoryId = $v->optionalId('category_id');
        $subcategoryId = $v->optionalId('subcategory_id');
        $transferTo = $v->optionalId('transfer_to_account_id');
        $paymentMethod = $v->optionalString('payment_method', 60);

        if ($type === 'transfer') {
            if ($transferTo === null) {
                $v->add('transfer_to_account_id', 'A transfer needs a destination account.');
            } elseif ($transferTo === $accountId) {
                $v->add('transfer_to_account_id', 'A transfer needs two different accounts.');
            }
            if ($categoryId !== null) {
                $v->add('category_id', 'Transfers are not categorised.');
            }
            if ($subcategoryId !== null) {
                $v->add('subcategory_id', 'Transfers are not categorised.');
            }
        } else {
            if ($transferTo !== null) {
                $v->add('transfer_to_account_id', 'Only transfers may have a destination account.');
            }
            if ($categoryId === null) {
                $v->add('category_id', 'Category is required.');
            }
        }

        $v->validate();

        // Ownership checks run after shape checks so a bad payload reports all
        // of its problems at once rather than one lookup failure at a time.
        $account = $this->accounts->requireAccount($userId, $accountId);

        if ((bool) $account['archived']) {
            throw new ValidationException(['account_id' => 'That account is archived.']);
        }

        if ($type === 'transfer') {
            /** @var int $transferTo */
            $destination = $this->accounts->requireAccount($userId, $transferTo);

            if ((bool) $destination['archived']) {
                throw new ValidationException(['transfer_to_account_id' => 'That account is archived.']);
            }
        }

        if ($categoryId !== null) {
            $this->categories->requireVisible($userId, $categoryId, $type);
        }

        if ($subcategoryId !== null) {
            /** @var int $categoryId */
            $this->subcategories->requireValid($subcategoryId, $categoryId);
        }

        return [
            'account_id' => $accountId,
            'category_id' => $categoryId,
            'subcategory_id' => $subcategoryId,
            'type' => $type,
            'amount' => $amount,
            'transfer_to_account_id' => $type === 'transfer' ? $transferTo : null,
            'description' => $description,
            'notes' => $notes,
            'tags' => $tags === null ? null : json_encode($tags, JSON_UNESCAPED_UNICODE),
            'transaction_date' => $date,
            'payment_method' => $paymentMethod,
        ];
    }

    /**
     * Existing row + the fields the client sent = the transaction it wants.
     *
     * @param array<string, mixed> $existing
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private function merge(array $existing, array $payload): array
    {
        $current = [
            'type' => $existing['type'],
            'account_id' => (int) $existing['account_id'],
            'category_id' => $existing['category_id'] === null ? null : (int) $existing['category_id'],
            'subcategory_id' => $existing['subcategory_id'] === null ? null : (int) $existing['subcategory_id'],
            'amount' => Money::toMajor((int) $existing['amount']),
            'transfer_to_account_id' => $existing['transfer_to_account_id'] === null
                ? null
                : (int) $existing['transfer_to_account_id'],
            'description' => $existing['description'],
            'notes' => $existing['notes'],
            'tags' => $this->decodeTags($existing['tags']),
            'transaction_date' => $existing['transaction_date'],
            'payment_method' => $existing['payment_method'],
        ];

        $merged = array_merge($current, array_intersect_key($payload, $current));

        // Switching an expense/income to a transfer drops the category, and
        // switching back drops the destination account, unless the client set
        // them explicitly in the same request.
        if ($merged['type'] === 'transfer' && !array_key_exists('category_id', $payload)) {
            $merged['category_id'] = null;
        }

        if ($merged['type'] === 'transfer' && !array_key_exists('subcategory_id', $payload)) {
            $merged['subcategory_id'] = null;
        }

        if ($merged['type'] !== 'transfer' && !array_key_exists('transfer_to_account_id', $payload)) {
            $merged['transfer_to_account_id'] = null;
        }

        return $merged;
    }

    /** @return array<string, mixed> */
    private function requireTransaction(int $userId, int $id): array
    {
        $transaction = $this->transactions->findForUser($id, $userId);

        if ($transaction === null) {
            throw NotFoundException::for('Transaction');
        }

        return $transaction;
    }

    /**
     * Tags are stored as a JSON array in a single column. A join table would
     * be the textbook answer, but tags here are free-text labels that are only
     * ever read back with their transaction -- a column keeps writes atomic
     * and reads join-free. If tag analytics ever matter, that is the moment to
     * normalise.
     *
     * @return array<int, string>
     */
    private function decodeTags(mixed $raw): array
    {
        if (!is_string($raw) || $raw === '') {
            return [];
        }

        $decoded = json_decode($raw, true);

        return is_array($decoded) ? array_values(array_filter($decoded, 'is_string')) : [];
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
            'transfer_to_account_id' => $row['transfer_to_account_id'] === null
                ? null
                : (int) $row['transfer_to_account_id'],
            'transfer_to_account_name' => $row['transfer_to_account_name'] ?? null,
            'category_id' => $row['category_id'] === null ? null : (int) $row['category_id'],
            'category_name' => $row['category_name'] ?? null,
            'category_icon' => $row['category_icon'] ?? null,
            'category_color' => $row['category_color'] ?? null,
            'subcategory_id' => $row['subcategory_id'] === null ? null : (int) $row['subcategory_id'],
            'subcategory_name' => $row['subcategory_name'] ?? null,
            'description' => $row['description'],
            'notes' => $row['notes'],
            'tags' => $this->decodeTags($row['tags'] ?? null),
            'transaction_date' => $row['transaction_date'],
            'payment_method' => $row['payment_method'] ?? null,
            'created_at' => $row['created_at'],
            'updated_at' => $row['updated_at'],
        ];
    }
}
