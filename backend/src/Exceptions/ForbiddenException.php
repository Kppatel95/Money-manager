<?php

declare(strict_types=1);

namespace App\Exceptions;

/**
 * The caller is authenticated and the resource exists, but this operation is
 * not allowed on it -- editing a system category, for example.
 *
 * Note the deliberate split from NotFoundException: anything belonging to
 * *another user* is reported as 404, because a 403 would confirm the row
 * exists.
 */
final class ForbiddenException extends HttpException
{
    public function __construct(string $message = 'This action is not allowed.', array $details = [])
    {
        parent::__construct($message, $details);
    }

    public function statusCode(): int
    {
        return 403;
    }

    public function errorCode(): string
    {
        return 'FORBIDDEN';
    }
}
