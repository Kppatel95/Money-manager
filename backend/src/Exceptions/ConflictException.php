<?php

declare(strict_types=1);

namespace App\Exceptions;

/** The request collides with existing state (duplicate email, duplicate budget). */
final class ConflictException extends HttpException
{
    public function statusCode(): int
    {
        return 409;
    }

    public function errorCode(): string
    {
        return 'CONFLICT';
    }
}
