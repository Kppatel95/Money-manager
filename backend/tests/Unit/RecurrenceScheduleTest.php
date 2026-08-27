<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\RecurringTransactionService;
use PHPUnit\Framework\TestCase;

/**
 * Pure date arithmetic for recurring schedules -- no database involved.
 */
final class RecurrenceScheduleTest extends TestCase
{
    public function testDailyAdvancesByOneDayAcrossMonthAndYearBoundaries(): void
    {
        $this->assertSame('2026-03-02', RecurringTransactionService::advance('2026-03-01', 'daily'));
        $this->assertSame('2026-03-01', RecurringTransactionService::advance('2026-02-28', 'daily'));
        $this->assertSame('2027-01-01', RecurringTransactionService::advance('2026-12-31', 'daily'));
    }

    public function testWeeklyAdvancesBySevenDays(): void
    {
        $this->assertSame('2026-03-08', RecurringTransactionService::advance('2026-03-01', 'weekly'));
        $this->assertSame('2026-04-04', RecurringTransactionService::advance('2026-03-28', 'weekly'));
    }

    public function testMonthlyKeepsTheDayOfMonth(): void
    {
        $this->assertSame('2026-04-15', RecurringTransactionService::advance('2026-03-15', 'monthly'));
        $this->assertSame('2027-01-01', RecurringTransactionService::advance('2026-12-01', 'monthly'));
    }

    public function testMonthlyClampsToTheEndOfAShorterMonth(): void
    {
        // A naive "+1 month" would overflow into March here.
        $this->assertSame('2026-02-28', RecurringTransactionService::advance('2026-01-31', 'monthly'));
        $this->assertSame('2026-06-30', RecurringTransactionService::advance('2026-05-31', 'monthly'));
    }

    public function testMonthlyHandlesALeapYear(): void
    {
        $this->assertSame('2028-02-29', RecurringTransactionService::advance('2028-01-31', 'monthly'));
    }
}
