<?php

declare(strict_types=1);

namespace App\Repositories;

use PDO;

/**
 * Failed login bookkeeping. Rows are written only on failure and cleared on a
 * successful login, so the table stays small and a legitimate user is never
 * penalised for a typo they immediately corrected.
 */
final class LoginAttemptRepository
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    public function record(string $email, ?string $ip, ?string $at = null): void
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO login_attempts (email, ip_address, attempted_at) VALUES (:email, :ip, :at)'
        );
        $stmt->execute(['email' => $email, 'ip' => $ip, 'at' => $at ?? gmdate('Y-m-d H:i:s')]);
    }

    public function countSince(string $email, string $since): int
    {
        $stmt = $this->pdo->prepare(
            'SELECT COUNT(*) FROM login_attempts WHERE email = :email AND attempted_at >= :since'
        );
        $stmt->execute(['email' => $email, 'since' => $since]);

        return (int) $stmt->fetchColumn();
    }

    /** Timestamp of the oldest failure still inside the window, if any. */
    public function oldestSince(string $email, string $since): ?string
    {
        $stmt = $this->pdo->prepare(
            'SELECT MIN(attempted_at) FROM login_attempts WHERE email = :email AND attempted_at >= :since'
        );
        $stmt->execute(['email' => $email, 'since' => $since]);
        $value = $stmt->fetchColumn();

        return is_string($value) ? $value : null;
    }

    public function clear(string $email): void
    {
        $stmt = $this->pdo->prepare('DELETE FROM login_attempts WHERE email = :email');
        $stmt->execute(['email' => $email]);
    }

    public function purgeBefore(string $before): int
    {
        $stmt = $this->pdo->prepare('DELETE FROM login_attempts WHERE attempted_at < :before');
        $stmt->execute(['before' => $before]);

        return $stmt->rowCount();
    }
}
