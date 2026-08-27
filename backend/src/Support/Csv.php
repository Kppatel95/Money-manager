<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Builds a CSV document in memory via fputcsv, so quoting, embedded commas,
 * newlines and double quotes are handled by the runtime rather than by
 * hand-rolled string concatenation.
 */
final class Csv
{
    /**
     * @param array<int, string> $headers
     * @param array<int, array<int, string|int|float|null>> $rows
     */
    public static function build(array $headers, array $rows, bool $withBom = true): string
    {
        $handle = fopen('php://temp', 'r+');

        if ($handle === false) {
            return '';
        }

        // The empty escape character is the RFC 4180 behaviour: quotes are
        // doubled, backslashes mean nothing special. It is also the default
        // PHP is moving towards, so passing it explicitly keeps the output
        // identical across versions.
        fputcsv($handle, $headers, ',', '"', '');

        foreach ($rows as $row) {
            fputcsv($handle, $row, ',', '"', '');
        }

        rewind($handle);
        $contents = (string) stream_get_contents($handle);
        fclose($handle);

        // Excel assumes the platform codepage unless a UTF-8 BOM says
        // otherwise, which is what turns category names into mojibake.
        return ($withBom ? "\xEF\xBB\xBF" : '') . $contents;
    }
}
