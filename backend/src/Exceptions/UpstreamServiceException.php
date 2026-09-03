<?php

declare(strict_types=1);

namespace App\Exceptions;

/** A dependency the API relies on (the Anthropic API, e.g.) failed or is not configured. */
final class UpstreamServiceException extends HttpException
{
    public function statusCode(): int
    {
        return 502;
    }

    public function errorCode(): string
    {
        return 'INTERNAL_ERROR';
    }
}
