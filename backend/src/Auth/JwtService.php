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
 * Issues and verifies the JWTs used to authenticate API requests.
 */
final class JwtService
{
    private const ALGO = 'HS256';
    private const TTL_SECONDS = 60 * 60 * 24; // 24 hours

    private string $secret;

    public function __construct(?string $secret = null)
    {
        $this->secret = $secret ?? Env::get('JWT_SECRET', 'dev-insecure-secret-change-me');
    }

    public function issue(int $userId, string $email): string
    {
        $now = time();

        $payload = [
            'sub' => $userId,
            'email' => $email,
            'iat' => $now,
            'exp' => $now + self::TTL_SECONDS,
        ];

        return JWT::encode($payload, $this->secret, self::ALGO);
    }

    /**
     * Returns the decoded payload as an array, or null if the token is
     * missing, malformed, expired, or has a bad signature.
     */
    public function verify(string $token): ?array
    {
        try {
            $decoded = JWT::decode($token, new Key($this->secret, self::ALGO));
            return (array) $decoded;
        } catch (ExpiredException|SignatureInvalidException|UnexpectedValueException) {
            return null;
        }
    }
}
