<?php

declare(strict_types=1);

namespace App\Migration;

use PDO;
use RuntimeException;

/**
 * Applies the numbered .sql files in database/migrations in order, exactly
 * once each, and records what it applied in a `migrations` ledger table.
 *
 * Deliberately tiny: no down-migrations, no checksums. Rolling forward with a
 * new file is the only way to change the schema, which keeps the history of
 * the database as linear and reviewable as the git history.
 */
final class Migrator
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly string $migrationsPath
    ) {
    }

    public static function defaultPath(): string
    {
        return dirname(__DIR__, 2) . '/database/migrations';
    }

    /**
     * Applies every migration that has not run yet.
     *
     * @return array<int, string> filenames that were applied by this call
     */
    public function migrate(): array
    {
        $this->ensureLedger();

        $applied = $this->appliedMigrations();
        $ran = [];

        foreach ($this->availableMigrations() as $file) {
            $name = basename($file);

            if (in_array($name, $applied, true)) {
                continue;
            }

            $sql = file_get_contents($file);

            if ($sql === false) {
                throw new RuntimeException("Unable to read migration {$name}.");
            }

            $this->pdo->beginTransaction();

            try {
                $this->pdo->exec($sql);
                $stmt = $this->pdo->prepare('INSERT INTO migrations (filename) VALUES (:filename)');
                $stmt->execute(['filename' => $name]);
                $this->pdo->commit();
            } catch (\Throwable $e) {
                $this->pdo->rollBack();
                throw new RuntimeException("Migration {$name} failed: " . $e->getMessage(), 0, $e);
            }

            $ran[] = $name;
        }

        return $ran;
    }

    /** @return array<int, string> migration filenames that have not run yet */
    public function pending(): array
    {
        $this->ensureLedger();
        $applied = $this->appliedMigrations();

        return array_values(array_filter(
            array_map('basename', $this->availableMigrations()),
            static fn (string $name): bool => !in_array($name, $applied, true)
        ));
    }

    /** @return array<int, string> */
    public function appliedMigrations(): array
    {
        $this->ensureLedger();
        $rows = $this->pdo->query('SELECT filename FROM migrations ORDER BY filename')->fetchAll();

        return array_map(static fn (array $row): string => $row['filename'], $rows);
    }

    /** @return array<int, string> absolute paths, sorted by filename */
    private function availableMigrations(): array
    {
        $files = glob(rtrim($this->migrationsPath, '/') . '/*.sql') ?: [];
        sort($files, SORT_STRING);

        return $files;
    }

    private function ensureLedger(): void
    {
        $this->pdo->exec(
            'CREATE TABLE IF NOT EXISTS migrations (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                filename TEXT NOT NULL UNIQUE,
                applied_at TEXT NOT NULL DEFAULT (datetime(\'now\'))
            )'
        );
    }
}
