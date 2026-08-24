<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Auth\JwtService;
use App\Repositories\UserRepository;
use App\Support\Request;
use App\Support\Response;
use App\Validation\AuthValidator;
use App\Validation\ValidationException;

final class AuthController
{
    public function __construct(
        private readonly UserRepository $users,
        private readonly JwtService $jwt
    ) {
    }

    public function register(Request $request): void
    {
        try {
            $data = AuthValidator::validateRegistration($request->all());
        } catch (ValidationException $e) {
            Response::error('Validation failed.', 422, $e->errors());
        }

        if ($this->users->findByEmail($data['email']) !== null) {
            Response::error('An account with that email already exists.', 409);
        }

        $user = $this->users->create(
            $data['name'],
            $data['email'],
            password_hash($data['password'], PASSWORD_BCRYPT)
        );

        $token = $this->jwt->issue((int) $user['id'], $user['email']);

        Response::json([
            'token' => $token,
            'user' => $this->publicUser($user),
        ], 201);
    }

    public function login(Request $request): void
    {
        try {
            $data = AuthValidator::validateLogin($request->all());
        } catch (ValidationException $e) {
            Response::error('Validation failed.', 422, $e->errors());
        }

        $user = $this->users->findByEmail($data['email']);

        if ($user === null || !password_verify($data['password'], $user['password_hash'])) {
            Response::error('Invalid email or password.', 401);
        }

        $token = $this->jwt->issue((int) $user['id'], $user['email']);

        Response::json([
            'token' => $token,
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
