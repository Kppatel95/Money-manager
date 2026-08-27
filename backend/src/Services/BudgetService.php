<?php

declare(strict_types=1);

namespace App\Services;

use App\Exceptions\ConflictException;
use App\Exceptions\NotFoundException;
use App\Repositories\BudgetRepository;
use App\Repositories\TransactionRepository;
use App\Support\Money;
use App\Validation\Validator;

/**
 * Monthly spending limits per category.
 *
 * "Spent" is never stored. It is the sum of that category's expense
 * transactions inside the budget's month, read back at request time, so a
 * back-dated or corrected transaction is reflected immediately and there is no
 * counter to rebuild. A whole month of budgets costs one extra grouped query,
 * not one query per budget.
 */
final class BudgetService
{
    public function __construct(
        private readonly BudgetRepository $budgets,
        private readonly TransactionRepository $transactions,
        private readonly CategoryService $categories
    ) {
    }

    /**
     * @return array{data: array<int, array<string, mixed>>, meta: array<string, mixed>}
     */
    public function listForMonth(int $userId, ?string $month = null): array
    {
        $month = $this->normaliseMonth($month);
        $rows = $this->budgets->forMonth($userId, $month);
        $spendByCategory = $this->transactions->spentByCategoryForMonth($userId, $month);

        $budgets = array_map(
            fn (array $row): array => $this->present($row, $spendByCategory[(int) $row['category_id']] ?? 0),
            $rows
        );

        $limit = array_sum(array_column($budgets, 'amount_limit_cents'));
        $spent = array_sum(array_column($budgets, 'spent_cents'));

        return [
            'data' => $budgets,
            'meta' => [
                'month' => $month,
                'total_limit_cents' => $limit,
                'total_limit' => Money::toMajor($limit),
                'total_spent_cents' => $spent,
                'total_spent' => Money::toMajor($spent),
                'total_remaining_cents' => $limit - $spent,
                'total_remaining' => Money::toMajor($limit - $spent),
            ],
        ];
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function create(int $userId, array $payload): array
    {
        $v = new Validator($payload);
        $categoryId = $v->requiredId('category_id');
        $month = $v->requiredMonth();
        $limit = $v->requiredAmountCents('amount_limit');
        $v->validate();

        $this->categories->requireVisible($userId, $categoryId, 'expense');

        if ($this->budgets->findByCategoryAndMonth($userId, $categoryId, $month) !== null) {
            throw new ConflictException('A budget for that category and month already exists.');
        }

        $row = $this->budgets->create($userId, $categoryId, $month, $limit);

        return $this->present($row, $this->transactions->spentInCategoryForMonth($userId, $categoryId, $month));
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function update(int $userId, int $budgetId, array $payload): array
    {
        $budget = $this->requireBudget($userId, $budgetId);

        $v = new Validator($payload);
        $fields = [];

        if ($v->has('amount_limit')) {
            $fields['amount_limit'] = $v->requiredAmountCents('amount_limit');
        }
        if ($v->has('month')) {
            $fields['month'] = $v->requiredMonth();
        }
        if ($v->has('category_id')) {
            $fields['category_id'] = $v->requiredId('category_id');
        }

        $v->validate();

        $categoryId = (int) ($fields['category_id'] ?? $budget['category_id']);
        $month = (string) ($fields['month'] ?? $budget['month']);

        if (isset($fields['category_id'])) {
            $this->categories->requireVisible($userId, $categoryId, 'expense');
        }

        if ($this->budgets->findByCategoryAndMonth($userId, $categoryId, $month, $budgetId) !== null) {
            throw new ConflictException('A budget for that category and month already exists.');
        }

        /** @var array<string, mixed> $row */
        $row = $this->budgets->update($budgetId, $userId, $fields);

        return $this->present($row, $this->transactions->spentInCategoryForMonth($userId, $categoryId, $month));
    }

    public function delete(int $userId, int $budgetId): void
    {
        $this->requireBudget($userId, $budgetId);
        $this->budgets->delete($budgetId, $userId);
    }

    /** @return array<string, mixed> */
    private function requireBudget(int $userId, int $budgetId): array
    {
        $budget = $this->budgets->findForUser($budgetId, $userId);

        if ($budget === null) {
            throw NotFoundException::for('Budget');
        }

        return $budget;
    }

    public function normaliseMonth(?string $month): string
    {
        if (is_string($month) && preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $month) === 1) {
            return $month;
        }

        return date('Y-m');
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    public function present(array $row, int $spentCents): array
    {
        $limit = (int) $row['amount_limit'];
        $remaining = $limit - $spentCents;

        return [
            'id' => (int) $row['id'],
            'category_id' => (int) $row['category_id'],
            'category_name' => $row['category_name'] ?? null,
            'category_icon' => $row['category_icon'] ?? null,
            'category_color' => $row['category_color'] ?? null,
            'month' => $row['month'],
            'amount_limit_cents' => $limit,
            'amount_limit' => Money::toMajor($limit),
            'spent_cents' => $spentCents,
            'spent' => Money::toMajor($spentCents),
            'remaining_cents' => $remaining,
            'remaining' => Money::toMajor($remaining),
            'percent_used' => $limit > 0 ? round($spentCents / $limit * 100, 1) : 0.0,
            'over_budget' => $spentCents > $limit,
            'created_at' => $row['created_at'] ?? null,
        ];
    }
}
