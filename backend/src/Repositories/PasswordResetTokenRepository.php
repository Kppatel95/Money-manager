<?php

declare(strict_types=1);

namespace App\Repositories;

use PDO;

/**
 * Password reset tokens are opaque random strings; only their SHA-256 hash is
 * stored, same reasoning as RefreshTokenRepository. Issuing a new token for a
 * user deletes any of their existing ones first, so at most one reset link is
 * ever valid at a time -- an old, forgotten email cannot be used later.
 */
final class PasswordResetTokenRepository
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    public function store(int $userId, string $tokenHash, string $expiresAt): int
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO password_reset_tokens (user_id, token_hash, expires_at) VALUES (:user_id, :hash, :expires_at)'
        );
        $stmt->execute(['user_id' => $userId, 'hash' => $tokenHash, 'expires_at' => $expiresAt]);

        return (int) $this->pdo->lastInsertId();
    }

    /** @return array<string, mixed>|null */
    public function findByHash(string $tokenHash): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM password_reset_tokens WHERE token_hash = :hash');
        $stmt->execute(['hash' => $tokenHash]);
        $row = $stmt->fetch();

        return $row === false ? null : $row;
    }

    public function delete(int $id): void
    {
        $stmt = $this->pdo->prepare('DELETE FROM password_reset_tokens WHERE id = :id');
        $stmt->execute(['id' => $id]);
    }

    public function deleteForUser(int $userId): void
    {
        $stmt = $this->pdo->prepare('DELETE FROM password_reset_tokens WHERE user_id = :user_id');
        $stmt->execute(['user_id' => $userId]);
    }

    /** Housekeeping: expired rows prove nothing and only grow the table. */
    public function deleteExpired(string $now): int
    {
        $stmt = $this->pdo->prepare('DELETE FROM password_reset_tokens WHERE expires_at < :now');
        $stmt->execute(['now' => $now]);

        return $stmt->rowCount();
    }
}
