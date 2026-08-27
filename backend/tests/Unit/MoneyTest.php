<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Support\Money;
use PHPUnit\Framework\TestCase;

final class MoneyTest extends TestCase
{
    public function testParsesMajorUnitsIntoCents(): void
    {
        $this->assertSame(1234, Money::toCents('12.34'));
        $this->assertSame(1234, Money::toCents(12.34));
        $this->assertSame(1200, Money::toCents(12));
        $this->assertSame(1234, Money::toCents('12,34'));
        $this->assertSame(-500, Money::toCents('-5'));
    }

    public function testRejectsNonNumericInput(): void
    {
        $this->assertNull(Money::toCents('abc'));
        $this->assertNull(Money::toCents(''));
        $this->assertNull(Money::toCents(null));
        $this->assertNull(Money::toCents(['12']));
    }

    public function testAvoidsBinaryFloatingPointDrift(): void
    {
        // 0.1 + 0.2 in floats is 0.30000000000000004; in cents it is exactly 30.
        $this->assertSame(30, Money::toCents('0.1') + Money::toCents('0.2'));
        $this->assertSame(1999, Money::toCents(19.99));
        $this->assertSame(70, Money::toCents(0.70));
    }

    public function testFormatsCentsBackToMajorUnits(): void
    {
        $this->assertSame(12.34, Money::toMajor(1234));
        $this->assertSame('12.34', Money::format(1234));
        $this->assertSame('0.05', Money::format(5));
        $this->assertSame('-3.00', Money::format(-300));
    }
}
