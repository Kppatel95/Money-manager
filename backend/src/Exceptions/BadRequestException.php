<?php

declare(strict_types=1);

namespace App\Exceptions;

/** The request itself is malformed (bad JSON, unusable query string, ...). */
final class BadRequestException extends HttpException
{
    public function statusCode(): int
    {
        return 400;
    }

    public function errorCode(): string
    {
        return 'BAD_REQUEST';
    }
}
