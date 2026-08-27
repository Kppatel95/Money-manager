<?php

declare(strict_types=1);

namespace App\Exceptions;

/**
 * The request was well-formed but the values in it are not acceptable.
 * details is a field => message map the client can render inline.
 */
final class ValidationException extends HttpException
{
    /** @param array<string, string> $errors */
    public function __construct(array $errors, string $message = 'The given data was invalid.')
    {
        parent::__construct($message, $errors);
    }

    public function statusCode(): int
    {
        return 422;
    }

    public function errorCode(): string
    {
        return 'VALIDATION_ERROR';
    }

    /** @return array<string, string> */
    public function errors(): array
    {
        /** @var array<string, string> */
        return $this->details();
    }
}
