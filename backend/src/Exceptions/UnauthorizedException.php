<?php

declare(strict_types=1);

namespace App\Exceptions;

/** No credentials, or credentials that no longer prove anything. */
final class UnauthorizedException extends HttpException
{
    public function __construct(string $message = 'Authentication required.', array $details = [])
    {
        parent::__construct($message, $details);
    }

    public function statusCode(): int
    {
        return 401;
    }

    public function errorCode(): string
    {
        return 'UNAUTHORIZED';
    }
}
