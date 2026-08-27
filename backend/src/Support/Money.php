<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Money is stored and computed as integer cents, never as a float.
 *
 * 0.1 + 0.2 != 0.3 in binary floating point, and a ledger that sums thousands
 * of rows will drift. Cents are exact, SUM() in SQLite stays exact, and the
 * only place a decimal appears is at the edges: parsing user input and
 * formatting output.
 */
final class Money
{
    /**
     * Parse a major-unit amount ("12.34", 12.34, 12) into cents.
     * Returns null when the value is not a usable number.
     */
    public static function toCents(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value * 100;
        }

        if (is_float($value)) {
            return (int) round($value * 100);
        }

        if (is_string($value)) {
            $normalised = trim(str_replace([',', ' '], ['.', ''], $value));

            if ($normalised === '' || !is_numeric($normalised)) {
                return null;
            }

            return (int) round(((float) $normalised) * 100);
        }

        return null;
    }

    /** Cents back to a major-unit float, rounded to 2dp for JSON output. */
    public static function toMajor(int $cents): float
    {
        return round($cents / 100, 2);
    }

    /** Cents to a fixed 2-decimal string, e.g. for CSV export. */
    public static function format(int $cents): string
    {
        return number_format($cents / 100, 2, '.', '');
    }
}
