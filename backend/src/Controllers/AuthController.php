<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Exceptions\UnauthorizedException;
use App\Repositories\UserRepository;
use App\Services\AuthService;
use App\Support\Request;
use App\Support\Response;

final class AuthController extends Controller
{
    public function __construct(
        private readonly AuthService $auth,
        private readonly UserRepository $users
    ) {
    }

    public function register(Request $request): Response
    {
        return Response::created($this->auth->register($request->all()));
    }

    public function login(Request $request): Response
    {
        return Response::data($this->auth->login($request->all(), $request->clientIp()));
    }

    public function refresh(Request $request): Response
    {
        $token = $request->input('refresh_token');

        return Response::data($this->auth->refresh(is_string($token) ? $token : null));
    }

    public function logout(Request $request, int $userId): Response
    {
        $token = $request->input('refresh_token');
        $this->auth->logout($userId, is_string($token) ? $token : null);

        return Response::noContent();
    }

    public function me(Request $request, int $userId): Response
    {
        $user = $this->users->findById($userId);

        if ($user === null) {
            throw new UnauthorizedException('Invalid or expired access token.');
        }

        return Response::data($this->auth->publicUser($user));
    }
}
