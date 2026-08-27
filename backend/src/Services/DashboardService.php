<?php

declare(strict_types=1);

namespace App\Services;

use App\Repositories\AccountRepository;
use App\Repositories\TransactionRepository;
use App\Support\Money;
use DateTimeImmutable;

/**
 * Read-only aggregation for the home screen: net worth, this month's totals,
 * where the money went, a six-month trend and budget progress.
 *
 * All of it is computed from the ledger on demand with a handful of grouped
 * queries rather than maintained in summary tables. At personal-finance scale
 * that is the right call -- a few thousand rows aggregate in microseconds, and
 * nothing can be stale or need rebuilding. Caching belongs here, behind this
 * one class, if a dataset ever outgrows it.
 */
final class DashboardService
{
    private const TREND_MONTHS = 6;

    public function __construct(
        private readonly AccountRepository $accounts,
        private readonly TransactionRepository $transactions,
        private readonly AccountService $accountService,
        private readonly BudgetService $budgets
    ) {
    }

    /** @return array<string, mixed> */
    public function summary(int $userId, ?string $month = null): array
    {
        $month = $this->budgets->normaliseMonth($month);
        [$from, $to] = self::monthBounds($month);

        $accounts = array_map(
            [$this->accountService, 'present'],
            $this->accounts->allForUser($userId, includeArchived: false)
        );

        $netWorth = array_sum(array_column($accounts, 'balance_cents'));
        $totals = $this->transactions->totalsBetween($userId, $from, $to);
        $net = $totals['income'] - $totals['expense'];

        return [
            'month' => $month,
            'net_worth_cents' => $netWorth,
            'net_worth' => Money::toMajor($netWorth),
            'accounts' => $accounts,
            'totals' => [
                'income_cents' => $totals['income'],
                'income' => Money::toMajor($totals['income']),
                'expense_cents' => $totals['expense'],
                'expense' => Money::toMajor($totals['expense']),
                'net_cents' => $net,
                'net' => Money::toMajor($net),
                'savings_rate' => $totals['income'] > 0
                    ? round($net / $totals['income'] * 100, 1)
                    : 0.0,
            ],
            'category_breakdown' => $this->categoryBreakdown($userId, $from, $to),
            'trend' => $this->trend($userId, $month),
            'budgets' => $this->budgets->listForMonth($userId, $month)['data'],
        ];
    }

    /**
     * Spend per category for a date range, with each slice's share of the
     * total so a client can draw the chart without re-deriving percentages.
     *
     * @return array<int, array<string, mixed>>
     */
    public function categoryBreakdown(int $userId, string $from, string $to, string $type = 'expense'): array
    {
        $rows = $this->transactions->categoryTotals($userId, $from, $to, $type);
        $total = array_sum(array_map(static fn (array $row): int => (int) $row['total'], $rows));

        return array_map(static function (array $row) use ($total): array {
            $amount = (int) $row['total'];

            return [
                'category_id' => $row['category_id'] === null ? null : (int) $row['category_id'],
                'name' => $row['category_name'],
                'icon' => $row['category_icon'],
                'color' => $row['category_color'],
                'total_cents' => $amount,
                'total' => Money::toMajor($amount),
                'transaction_count' => (int) $row['transaction_count'],
                'percent' => $total > 0 ? round($amount / $total * 100, 1) : 0.0,
            ];
        }, $rows);
    }

    /**
     * Income and expense per month for the last six months, oldest first.
     * Months with no activity are present with zeroes so a chart does not have
     * to fill the gaps itself.
     *
     * @return array<int, array<string, mixed>>
     */
    public function trend(int $userId, string $month, int $months = self::TREND_MONTHS): array
    {
        $end = new DateTimeImmutable($month . '-01');
        $start = $end->modify('-' . ($months - 1) . ' months');

        $totals = $this->transactions->monthlyTotals(
            $userId,
            $start->format('Y-m'),
            $end->format('Y-m')
        );

        $trend = [];

        for ($i = 0; $i < $months; $i++) {
            $key = $start->modify("+{$i} months")->format('Y-m');
            $income = $totals[$key]['income'] ?? 0;
            $expense = $totals[$key]['expense'] ?? 0;

            $trend[] = [
                'month' => $key,
                'income_cents' => $income,
                'income' => Money::toMajor($income),
                'expense_cents' => $expense,
                'expense' => Money::toMajor($expense),
                'net_cents' => $income - $expense,
                'net' => Money::toMajor($income - $expense),
            ];
        }

        return $trend;
    }

    /** @return array{0: string, 1: string} first and last day of a 'YYYY-MM' month */
    public static function monthBounds(string $month): array
    {
        $first = new DateTimeImmutable($month . '-01');

        return [$first->format('Y-m-d'), $first->format('Y-m-t')];
    }
}
