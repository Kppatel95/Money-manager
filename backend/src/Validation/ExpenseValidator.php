<?php

declare(strict_types=1);

namespace App\Validation;

/**
 * Validates and normalizes expense input. Deliberately has zero knowledge
 * of HTTP or the database so it can be unit tested in isolation.
 */
final class ExpenseValidator
{
    private const MAX_CATEGORY_LENGTH = 50;
    private const MAX_DESCRIPTION_LENGTH = 255;

    /**
     * @param array<string, mixed> $data
     * @param bool $partial When true, missing fields are skipped instead of
     *                       flagged (used for PUT, where a client may only
     *                       send the fields they want to change).
     * @return array{amount?: float, category?: string, description?: ?string, expense_date?: string}
     * @throws ValidationException
     */
    public static function validate(array $data, bool $partial = false): array
    {
        $errors = [];
        $clean = [];

        if (array_key_exists('amount', $data) || !$partial) {
            $amount = self::validateAmount($data['amount'] ?? null, $errors);
            if ($amount !== null) {
                $clean['amount'] = $amount;
            }
        }

        if (array_key_exists('category', $data) || !$partial) {
            $category = self::validateCategory($data['category'] ?? null, $errors);
            if ($category !== null) {
                $clean['category'] = $category;
            }
        }

        if (array_key_exists('expense_date', $data) || !$partial) {
            $date = self::validateDate($data['expense_date'] ?? null, $errors);
            if ($date !== null) {
                $clean['expense_date'] = $date;
            }
        }

        if (array_key_exists('description', $data)) {
            $clean['description'] = self::validateDescription($data['description'], $errors);
        }

        if ($errors !== []) {
            throw new ValidationException($errors);
        }

        return $clean;
    }

    private static function validateAmount(mixed $value, array &$errors): ?float
    {
        if ($value === null || $value === '') {
            $errors['amount'] = 'Amount is required.';
            return null;
        }

        if (!is_numeric($value)) {
            $errors['amount'] = 'Amount must be a number.';
            return null;
        }

        $amount = (float) $value;

        if ($amount <= 0) {
            $errors['amount'] = 'Amount must be greater than zero.';
            return null;
        }

        if ($amount > 100_000_000) {
            $errors['amount'] = 'Amount is unrealistically large.';
            return null;
        }

        return round($amount, 2);
    }

    private static function validateCategory(mixed $value, array &$errors): ?string
    {
        if (!is_string($value) || trim($value) === '') {
            $errors['category'] = 'Category is required.';
            return null;
        }

        $category = trim($value);

        if (mb_strlen($category) > self::MAX_CATEGORY_LENGTH) {
            $errors['category'] = 'Category must be ' . self::MAX_CATEGORY_LENGTH . ' characters or fewer.';
            return null;
        }

        return $category;
    }

    private static function validateDescription(mixed $value, array &$errors): ?string
    {
        if ($value === null) {
            return null;
        }

        if (!is_string($value)) {
            $errors['description'] = 'Description must be text.';
            return null;
        }

        $description = trim($value);

        if (mb_strlen($description) > self::MAX_DESCRIPTION_LENGTH) {
            $errors['description'] = 'Description must be ' . self::MAX_DESCRIPTION_LENGTH . ' characters or fewer.';
            return null;
        }

        return $description === '' ? null : $description;
    }

    private static function validateDate(mixed $value, array &$errors): ?string
    {
        if (!is_string($value) || trim($value) === '') {
            $errors['expense_date'] = 'Date is required.';
            return null;
        }

        $value = trim($value);
        $date = \DateTime::createFromFormat('Y-m-d', $value);
        $formatErrors = \DateTime::getLastErrors();

        $hasFormatErrors = $formatErrors !== false
            && ($formatErrors['warning_count'] > 0 || $formatErrors['error_count'] > 0);

        if (!$date || $hasFormatErrors) {
            $errors['expense_date'] = 'Date must be in YYYY-MM-DD format.';
            return null;
        }

        if ($date->format('Y-m-d') !== $value) {
            $errors['expense_date'] = 'Date must be in YYYY-MM-DD format.';
            return null;
        }

        return $value;
    }
}
