<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;

/**
 * Base class for the domain errors the API knows how to answer with.
 *
 * Services and controllers throw these; a single handler
 * (App\Http\ErrorHandler) turns them into the JSON error envelope. Nothing
 * below the HTTP layer ever calls http_response_code() itself.
 */
abstract class HttpException extends RuntimeException
{
    /** @param array<string, mixed> $details */
    public function __construct(
        string $message,
        private readonly array $details = []
    ) {
        parent::__construct($message);
    }

    abstract public function statusCode(): int;

    /** Machine-readable code returned as error.code. */
    abstract public function errorCode(): string;

    /** @return array<string, mixed> */
    public function details(): array
    {
        return $this->details;
    }

    /** @return array<string, string> extra response headers, if any */
    public function headers(): array
    {
        return [];
    }
}
