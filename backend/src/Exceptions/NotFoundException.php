<?php

declare(strict_types=1);

namespace App\Exceptions;

/** The resource does not exist, or does not belong to the current user. */
final class NotFoundException extends HttpException
{
    public function __construct(string $message = 'Resource not found.', array $details = [])
    {
        parent::__construct($message, $details);
    }

    public static function for(string $resource): self
    {
        return new self($resource . ' not found.');
    }

    public function statusCode(): int
    {
        return 404;
    }

    public function errorCode(): string
    {
        return 'NOT_FOUND';
    }
}
