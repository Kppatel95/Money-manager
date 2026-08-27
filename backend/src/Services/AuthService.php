<?php

declare(strict_types=1);

namespace App\Services;

use App\Auth\JwtService;
use App\Exceptions\ConflictException;
use App\Exceptions\RateLimitedException;
use App\Exceptions\UnauthorizedException;
use App\Repositories\LoginAttemptRepository;
use App\Repositories\RefreshTokenRepository;
use App\Repositories\UserRepository;
use App\Support\Logger;
use App\Validation\Validator;

/**
 * Registration, login, token refresh and logout.
 *
 * The token pair is the point of this class. The access token is a stateless
 * 15-minute JWT: fast to verify, impossible to revoke. The refresh token is a
 * 256-bit opaque string stored only as a SHA-256 hash, valid for a week, and
 * rotated on every use -- presenting one revokes it and issues a replacement,
 * so a stolen refresh token stops working as soon as the real user refreshes.
 * Logout revokes the refresh token; the access token keeps working until it
 * expires, which is the normal tradeoff for stateless access tokens and the
 * reason theirs is short.
 */
final class AuthService
{
    public const MAX_FAILED_ATTEMPTS = 5;
    public const ATTEMPT_WINDOW_SECONDS = 900;   // 15 minutes
    public const REFRESH_TTL_SECONDS = 604800;   // 7 days

    public function __construct(
        private readonly UserRepository $users,
        private readonly RefreshTokenRepository $refreshTokens,
        private readonly LoginAttemptRepository $attempts,
        private readonly JwtService $jwt,
        private readonly Logger $logger = new Logger(null)
    ) {
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function register(array $payload): array
    {
        $v = new Validator($payload);
        $name = $v->requiredString('name', 80);
        $email = $v->requiredEmail();
        $password = $v->requiredPassword();
        $v->validate();

        if ($this->users->findByEmail($email) !== null) {
            throw new ConflictException('An account with that email already exists.');
        }

        $user = $this->users->create($name, $email, password_hash($password, PASSWORD_BCRYPT));

        $this->logger->info('Registered user {email}.', ['email' => $email, 'user_id' => (int) $user['id']]);

        // No per-user category seeding: the default categories are global rows
        // (user_id IS NULL) created by a migration and visible to everyone, so
        // a new account starts with a usable set without copying ten rows.
        return $this->issueTokens($user);
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function login(array $payload, ?string $ip = null): array
    {
        $v = new Validator($payload);
        $email = $v->requiredEmail();
        $password = $v->requiredPassword('password', 1);
        $v->validate();

        $this->assertNotLockedOut($email, $ip);

        $user = $this->users->findByEmail($email);

        if ($user === null || !password_verify($password, $user['password_hash'])) {
            $this->attempts->record($email, $ip);
            $this->logger->warning('Failed login for {email} from {ip}.', ['email' => $email, 'ip' => $ip ?? 'unknown']);

            throw new UnauthorizedException('Invalid email or password.');
        }

        $this->attempts->clear($email);

        return $this->issueTokens($user);
    }

    /**
     * Rotates a refresh token: the presented one is revoked and a fresh pair
     * is issued.
     *
     * @return array<string, mixed>
     */
    public function refresh(?string $refreshToken): array
    {
        if (!is_string($refreshToken) || $refreshToken === '') {
            throw new UnauthorizedException('A refresh token is required.');
        }

        $row = $this->refreshTokens->findByHash($this->hash($refreshToken));

        if ($row === null || (int) $row['revoked'] === 1 || $row['expires_at'] < gmdate('Y-m-d H:i:s')) {
            $this->logger->warning('Refresh rejected: unknown, revoked or expired token.');

            throw new UnauthorizedException('That refresh token is no longer valid.');
        }

        $user = $this->users->findById((int) $row['user_id']);

        if ($user === null) {
            throw new UnauthorizedException('That refresh token is no longer valid.');
        }

        $this->refreshTokens->revoke((int) $row['id']);

        return $this->issueTokens($user);
    }

    /** Revoking is idempotent: logging out twice is not an error. */
    public function logout(int $userId, ?string $refreshToken): void
    {
        if (!is_string($refreshToken) || $refreshToken === '') {
            $this->refreshTokens->revokeAllForUser($userId);
            return;
        }

        $row = $this->refreshTokens->findByHash($this->hash($refreshToken));

        if ($row !== null && (int) $row['user_id'] === $userId) {
            $this->refreshTokens->revoke((int) $row['id']);
        }
    }

    /**
     * @param array<string, mixed> $user
     * @return array<string, mixed>
     */
    public function publicUser(array $user): array
    {
        return [
            'id' => (int) $user['id'],
            'name' => $user['name'],
            'email' => $user['email'],
            'created_at' => $user['created_at'] ?? null,
        ];
    }

    /**
     * @param array<string, mixed> $user
     * @return array<string, mixed>
     */
    private function issueTokens(array $user): array
    {
        $userId = (int) $user['id'];
        $refreshToken = bin2hex(random_bytes(32));

        $this->refreshTokens->store(
            $userId,
            $this->hash($refreshToken),
            gmdate('Y-m-d H:i:s', time() + self::REFRESH_TTL_SECONDS)
        );

        // Cheap housekeeping while we are already writing to the table.
        $this->refreshTokens->deleteExpired(gmdate('Y-m-d H:i:s'));

        return [
            'user' => $this->publicUser($user),
            'access_token' => $this->jwt->issue($userId, $user['email']),
            'refresh_token' => $refreshToken,
            'token_type' => 'Bearer',
            'expires_in' => $this->jwt->ttl(),
        ];
    }

    private function assertNotLockedOut(string $email, ?string $ip): void
    {
        $since = gmdate('Y-m-d H:i:s', time() - self::ATTEMPT_WINDOW_SECONDS);
        $failures = $this->attempts->countSince($email, $since);

        if ($failures < self::MAX_FAILED_ATTEMPTS) {
            return;
        }

        $oldest = $this->attempts->oldestSince($email, $since);
        $retryAfter = self::ATTEMPT_WINDOW_SECONDS;

        if ($oldest !== null) {
            $elapsed = time() - (int) strtotime($oldest . ' UTC');
            $retryAfter = max(1, self::ATTEMPT_WINDOW_SECONDS - $elapsed);
        }

        $this->logger->warning('Login locked out for {email} from {ip} after {failures} failures.', [
            'email' => $email,
            'ip' => $ip ?? 'unknown',
            'failures' => $failures,
        ]);

        throw new RateLimitedException(
            'Too many failed login attempts. Try again in ' . (int) ceil($retryAfter / 60) . ' minute(s).',
            $retryAfter
        );
    }

    private function hash(string $token): string
    {
        return hash('sha256', $token);
    }
}
