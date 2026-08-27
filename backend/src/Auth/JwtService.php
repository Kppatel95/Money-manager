<?php

declare(strict_types=1);

namespace App\Auth;

use App\Support\Env;
use Firebase\JWT\ExpiredException;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Firebase\JWT\SignatureInvalidException;
use UnexpectedValueException;

/**
 * Issues and verifies the short-lived access tokens used on every request.
 *
 * 15 minutes is deliberate: a stateless JWT cannot be revoked, so the way to
 * limit the damage of a leaked one is to make it expire quickly and put the
 * long-lived, revocable half of the pair in the refresh token instead.
 */
final class JwtService
{
    private const ALGO = 'HS256';
    public const DEFAULT_TTL = 900; // 15 minutes

    private string $secret;

    private int $ttl;

    public function __construct(?string $secret = null, ?int $ttl = null)
    {
        $this->secret = $secret ?? Env::get('JWT_SECRET', 'dev-insecure-secret-change-me');
        $this->ttl = $ttl ?? (int) (Env::get('JWT_TTL', (string) self::DEFAULT_TTL));
    }

    public function ttl(): int
    {
        return $this->ttl;
    }

    public function issue(int $userId, string $email): string
    {
        $now = time();

        return JWT::encode([
            'sub' => $userId,
            'email' => $email,
            'iat' => $now,
            'exp' => $now + $this->ttl,
        ], $this->secret, self::ALGO);
    }

    /**
     * Returns the decoded payload, or null if the token is malformed, expired
     * or signed with the wrong key.
     *
     * @return array<string, mixed>|null
     */
    public function verify(string $token): ?array
    {
        try {
            return (array) JWT::decode($token, new Key($this->secret, self::ALGO));
        } catch (ExpiredException|SignatureInvalidException|UnexpectedValueException) {
            return null;
        }
    }
}
