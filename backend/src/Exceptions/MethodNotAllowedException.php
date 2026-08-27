<?php

declare(strict_types=1);

namespace App\Exceptions;

/** The path exists but not for this HTTP verb. */
final class MethodNotAllowedException extends HttpException
{
    public function __construct(string $message = 'Method not allowed.', array $details = [])
    {
        parent::__construct($message, $details);
    }

    public function statusCode(): int
    {
        return 405;
    }

    public function errorCode(): string
    {
        return 'METHOD_NOT_ALLOWED';
    }
}
