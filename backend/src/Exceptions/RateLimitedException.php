<?php

declare(strict_types=1);

namespace App\Exceptions;

/** Too many attempts; the caller must wait before trying again. */
final class RateLimitedException extends HttpException
{
    public function __construct(
        string $message,
        private readonly int $retryAfterSeconds = 900
    ) {
        parent::__construct($message, ['retry_after_seconds' => $retryAfterSeconds]);
    }

    public function statusCode(): int
    {
        return 429;
    }

    public function errorCode(): string
    {
        return 'RATE_LIMITED';
    }

    public function headers(): array
    {
        return ['Retry-After' => (string) $this->retryAfterSeconds];
    }

    public function retryAfterSeconds(): int
    {
        return $this->retryAfterSeconds;
    }
}
