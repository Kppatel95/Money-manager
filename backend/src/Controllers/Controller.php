<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Exceptions\NotFoundException;

/**
 * Shared helpers for the HTTP layer. Controllers stay thin: read the request,
 * call a service, wrap the result in a Response. No SQL, no business rules.
 */
abstract class Controller
{
    /**
     * Path parameters are strings; anything that is not a positive integer
     * cannot identify a row, so it is a 404 rather than a validation error.
     *
     * @param array<string, string> $params
     */
    protected function id(array $params, string $key = 'id'): int
    {
        $value = $params[$key] ?? '';

        if (!ctype_digit($value) || (int) $value <= 0) {
            throw new NotFoundException('Resource not found.');
        }

        return (int) $value;
    }
}
