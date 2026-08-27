<?php

declare(strict_types=1);

namespace App\Repositories;

use PDO;

/**
 * Refresh tokens are opaque random strings; only their SHA-256 hash is stored,
 * so a dump of this table cannot be replayed against the API. Lookups hash the
 * presented token and compare, exactly like a password check without the
 * per-row salt (the token already has 256 bits of entropy, so a fast hash is
 * the right choice here -- there is nothing to brute force).
 */
final class RefreshTokenRepository
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    public function store(int $userId, string $tokenHash, string $expiresAt): int
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO refresh_tokens (user_id, token_hash, expires_at) VALUES (:user_id, :hash, :expires_at)'
        );
        $stmt->execute(['user_id' => $userId, 'hash' => $tokenHash, 'expires_at' => $expiresAt]);

        return (int) $this->pdo->lastInsertId();
    }

    /** @return array<string, mixed>|null */
    public function findByHash(string $tokenHash): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM refresh_tokens WHERE token_hash = :hash');
        $stmt->execute(['hash' => $tokenHash]);
        $row = $stmt->fetch();

        return $row === false ? null : $row;
    }

    public function revoke(int $id): void
    {
        $stmt = $this->pdo->prepare('UPDATE refresh_tokens SET revoked = 1 WHERE id = :id');
        $stmt->execute(['id' => $id]);
    }

    public function revokeAllForUser(int $userId): void
    {
        $stmt = $this->pdo->prepare('UPDATE refresh_tokens SET revoked = 1 WHERE user_id = :user_id AND revoked = 0');
        $stmt->execute(['user_id' => $userId]);
    }

    /** Housekeeping: expired rows prove nothing and only grow the table. */
    public function deleteExpired(string $now): int
    {
        $stmt = $this->pdo->prepare('DELETE FROM refresh_tokens WHERE expires_at < :now');
        $stmt->execute(['now' => $now]);

        return $stmt->rowCount();
    }

    public function activeCountForUser(int $userId, string $now): int
    {
        $stmt = $this->pdo->prepare(
            'SELECT COUNT(*) FROM refresh_tokens WHERE user_id = :user_id AND revoked = 0 AND expires_at >= :now'
        );
        $stmt->execute(['user_id' => $userId, 'now' => $now]);

        return (int) $stmt->fetchColumn();
    }
}
