<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Auth\JwtService;
use App\Exceptions\ConflictException;
use App\Exceptions\UnauthorizedException;
use App\Repositories\UserRepository;
use App\Support\Request;
use App\Support\Response;
use App\Validation\AuthValidator;

/**
 * v1 (legacy) auth endpoints: a single long-lived JWT, no refresh tokens.
 * Superseded by /api/v1/auth; kept mounted until the frontend moves over.
 */
final class AuthController
{
    public function __construct(
        private readonly UserRepository $users,
        private readonly JwtService $jwt
    ) {
    }

    public function register(Request $request): Response
    {
        $data = AuthValidator::validateRegistration($request->all());

        if ($this->users->findByEmail($data['email']) !== null) {
            throw new ConflictException('An account with that email already exists.');
        }

        $user = $this->users->create(
            $data['name'],
            $data['email'],
            password_hash($data['password'], PASSWORD_BCRYPT)
        );

        return Response::json([
            'token' => $this->jwt->issue((int) $user['id'], $user['email']),
            'user' => $this->publicUser($user),
        ], 201);
    }

    public function login(Request $request): Response
    {
        $data = AuthValidator::validateLogin($request->all());
        $user = $this->users->findByEmail($data['email']);

        if ($user === null || !password_verify($data['password'], $user['password_hash'])) {
            throw new UnauthorizedException('Invalid email or password.');
        }

        return Response::json([
            'token' => $this->jwt->issue((int) $user['id'], $user['email']),
            'user' => $this->publicUser($user),
        ]);
    }

    private function publicUser(array $user): array
    {
        return [
            'id' => (int) $user['id'],
            'name' => $user['name'],
            'email' => $user['email'],
        ];
    }
}
