<?php

declare(strict_types=1);

namespace App\Auth;

use App\Repositories\UserRepository;
use App\Support\Request;
use App\Support\Response;

/**
 * Resolves the currently authenticated user from the request's Bearer
 * token, or halts the request with a 401 JSON response.
 */
final class Authenticate
{
    public function __construct(
        private readonly JwtService $jwt,
        private readonly UserRepository $users
    ) {
    }

    public function handle(Request $request): array
    {
        $token = $request->bearerToken();

        if ($token === null) {
            Response::error('Authentication required.', 401);
        }

        $payload = $this->jwt->verify($token);

        if ($payload === null || !isset($payload['sub'])) {
            Response::error('Invalid or expired token.', 401);
        }

        $user = $this->users->findById((int) $payload['sub']);

        if ($user === null) {
            Response::error('Invalid or expired token.', 401);
        }

        return $user;
    }
}
