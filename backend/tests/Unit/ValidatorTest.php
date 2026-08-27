<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Exceptions\ValidationException;
use App\Validation\Validator;
use PHPUnit\Framework\TestCase;

final class ValidatorTest extends TestCase
{
    public function testCollectsEveryFieldErrorBeforeThrowing(): void
    {
        $v = new Validator(['name' => '', 'type' => 'gold', 'amount' => 'x']);
        $v->requiredString('name');
        $v->requiredEnum('type', ['cash', 'bank']);
        $v->requiredAmountCents('amount');

        try {
            $v->validate();
            $this->fail('Expected a ValidationException.');
        } catch (ValidationException $e) {
            $this->assertSame(['name', 'type', 'amount'], array_keys($e->errors()));
            $this->assertSame(422, $e->statusCode());
        }
    }

    public function testPassesCleanInputThrough(): void
    {
        $v = new Validator([
            'name' => '  Main Wallet ',
            'type' => 'cash',
            'amount' => '15.50',
            'transaction_date' => '2026-02-28',
            'month' => '2026-02',
            'color' => '#AABBCC',
            'tags' => ['work', ' work ', 'travel', ''],
        ]);

        $this->assertSame('Main Wallet', $v->requiredString('name'));
        $this->assertSame('cash', $v->requiredEnum('type', ['cash', 'bank']));
        $this->assertSame(1550, $v->requiredAmountCents('amount'));
        $this->assertSame('2026-02-28', $v->requiredDate('transaction_date'));
        $this->assertSame('2026-02', $v->requiredMonth());
        $this->assertSame('#aabbcc', $v->optionalColor());
        $this->assertSame(['work', 'travel'], $v->optionalTags());

        $v->validate();
        $this->assertSame([], $v->errors());
    }

    public function testRejectsImpossibleDatesAndMonths(): void
    {
        $v = new Validator(['transaction_date' => '2026-02-30', 'month' => '2026-13']);
        $v->requiredDate('transaction_date');
        $v->requiredMonth();

        $this->assertArrayHasKey('transaction_date', $v->errors());
        $this->assertArrayHasKey('month', $v->errors());
    }

    public function testOptionalReadersIgnoreAbsentKeys(): void
    {
        $v = new Validator([]);

        $this->assertNull($v->optionalString('name'));
        $this->assertNull($v->optionalAmountCents('amount'));
        $this->assertNull($v->optionalBool('archived'));
        $this->assertNull($v->optionalId('category_id'));
        $this->assertSame([], $v->errors());
    }

    public function testAcceptsCommaSeparatedTags(): void
    {
        $v = new Validator(['tags' => 'work, travel ,work']);

        $this->assertSame(['work', 'travel'], $v->optionalTags());
    }

    public function testRejectsZeroAndNegativeAmounts(): void
    {
        $v = new Validator(['amount' => '0']);
        $v->requiredAmountCents('amount');
        $this->assertArrayHasKey('amount', $v->errors());

        $v2 = new Validator(['amount' => '-4.20']);
        $v2->requiredAmountCents('amount');
        $this->assertArrayHasKey('amount', $v2->errors());

        // Opening balances are allowed to be negative (an overdrawn account).
        $v3 = new Validator(['initial_balance' => '-4.20']);
        $this->assertSame(-420, $v3->signedAmountCents('initial_balance'));
        $this->assertSame([], $v3->errors());
    }
}
