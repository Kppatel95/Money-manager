<?php

declare(strict_types=1);

namespace App\Auth;

use App\Exceptions\UnauthorizedException;
use App\Repositories\UserRepository;
use App\Support\Request;

/**
 * Resolves the authenticated user from the request's Bearer token, or throws
 * an UnauthorizedException that the error handler turns into a 401.
 */
final class Authenticate
{
    public function __construct(
        private readonly JwtService $jwt,
        private readonly UserRepository $users
    ) {
    }

    /** @return array<string, mixed> the authenticated user row */
    public function handle(Request $request): array
    {
        $token = $request->bearerToken();

        if ($token === null) {
            throw new UnauthorizedException('Authentication required.');
        }

        $payload = $this->jwt->verify($token);

        if ($payload === null || !isset($payload['sub'])) {
            throw new UnauthorizedException('Invalid or expired access token.');
        }

        $user = $this->users->findById((int) $payload['sub']);

        if ($user === null) {
            throw new UnauthorizedException('Invalid or expired access token.');
        }

        return $user;
    }
}
