<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Validation\ExpenseValidator;
use App\Exceptions\ValidationException;
use PHPUnit\Framework\TestCase;

final class ExpenseValidatorTest extends TestCase
{
    public function testValidPayloadIsNormalized(): void
    {
        $result = ExpenseValidator::validate([
            'amount' => '19.999',
            'category' => '  Groceries  ',
            'description' => '  Weekly shop  ',
            'expense_date' => '2026-08-01',
        ]);

        self::assertSame(20.0, $result['amount']);
        self::assertSame('Groceries', $result['category']);
        self::assertSame('Weekly shop', $result['description']);
        self::assertSame('2026-08-01', $result['expense_date']);
    }

    public function testMissingAmountFails(): void
    {
        $this->expectException(ValidationException::class);

        ExpenseValidator::validate([
            'category' => 'Food',
            'expense_date' => '2026-08-01',
        ]);
    }

    public function testZeroAmountFails(): void
    {
        try {
            ExpenseValidator::validate([
                'amount' => 0,
                'category' => 'Food',
                'expense_date' => '2026-08-01',
            ]);
            self::fail('Expected a ValidationException.');
        } catch (ValidationException $e) {
            self::assertArrayHasKey('amount', $e->errors());
        }
    }

    public function testNegativeAmountFails(): void
    {
        try {
            ExpenseValidator::validate([
                'amount' => -5,
                'category' => 'Food',
                'expense_date' => '2026-08-01',
            ]);
            self::fail('Expected a ValidationException.');
        } catch (ValidationException $e) {
            self::assertArrayHasKey('amount', $e->errors());
        }
    }

    public function testNonNumericAmountFails(): void
    {
        try {
            ExpenseValidator::validate([
                'amount' => 'not-a-number',
                'category' => 'Food',
                'expense_date' => '2026-08-01',
            ]);
            self::fail('Expected a ValidationException.');
        } catch (ValidationException $e) {
            self::assertArrayHasKey('amount', $e->errors());
        }
    }

    public function testBlankCategoryFails(): void
    {
        try {
            ExpenseValidator::validate([
                'amount' => 10,
                'category' => '   ',
                'expense_date' => '2026-08-01',
            ]);
            self::fail('Expected a ValidationException.');
        } catch (ValidationException $e) {
            self::assertArrayHasKey('category', $e->errors());
        }
    }

    /** @dataProvider invalidDateProvider */
    public function testInvalidDateFormatsFail(mixed $date): void
    {
        try {
            ExpenseValidator::validate([
                'amount' => 10,
                'category' => 'Food',
                'expense_date' => $date,
            ]);
            self::fail('Expected a ValidationException.');
        } catch (ValidationException $e) {
            self::assertArrayHasKey('expense_date', $e->errors());
        }
    }

    public static function invalidDateProvider(): array
    {
        return [
            'wrong format' => ['08/01/2026'],
            'not a real date' => ['2026-02-30'],
            'empty string' => [''],
            'not a string' => [12345],
        ];
    }

    public function testDescriptionIsOptionalAndBlankBecomesNull(): void
    {
        $result = ExpenseValidator::validate([
            'amount' => 10,
            'category' => 'Food',
            'expense_date' => '2026-08-01',
            'description' => '   ',
        ]);

        self::assertNull($result['description']);
    }

    public function testDescriptionTooLongFails(): void
    {
        try {
            ExpenseValidator::validate([
                'amount' => 10,
                'category' => 'Food',
                'expense_date' => '2026-08-01',
                'description' => str_repeat('x', 256),
            ]);
            self::fail('Expected a ValidationException.');
        } catch (ValidationException $e) {
            self::assertArrayHasKey('description', $e->errors());
        }
    }

    public function testMultipleErrorsAreAllReported(): void
    {
        try {
            ExpenseValidator::validate([
                'amount' => -1,
                'category' => '',
                'expense_date' => 'nope',
            ]);
            self::fail('Expected a ValidationException.');
        } catch (ValidationException $e) {
            $errors = $e->errors();
            self::assertCount(3, $errors);
            self::assertArrayHasKey('amount', $errors);
            self::assertArrayHasKey('category', $errors);
            self::assertArrayHasKey('expense_date', $errors);
        }
    }

    public function testPartialValidationSkipsMissingFields(): void
    {
        $result = ExpenseValidator::validate(['amount' => '99.5'], partial: true);

        self::assertSame(['amount' => 99.5], $result);
    }

    public function testPartialValidationStillValidatesFieldsThatArePresent(): void
    {
        $this->expectException(ValidationException::class);

        ExpenseValidator::validate(['amount' => -1], partial: true);
    }
}
