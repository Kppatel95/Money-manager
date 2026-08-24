<?php

declare(strict_types=1);

namespace App;

use App\Support\Env;
use PDO;

/**
 * Thin wrapper around a PDO/SQLite connection. Holds a single shared
 * connection per request; tests build their own instance pointed at a
 * temporary file instead of using the singleton.
 */
final class Database
{
    private static ?PDO $connection = null;

    public static function connection(): PDO
    {
        if (self::$connection === null) {
            $path = Env::get('DB_PATH', dirname(__DIR__) . '/database/expenses.sqlite');
            self::$connection = self::connect($path);
        }

        return self::$connection;
    }

    public static function connect(string $path): PDO
    {
        $isNew = $path !== ':memory:' && !is_file($path);

        $pdo = new PDO('sqlite:' . $path);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        $pdo->exec('PRAGMA foreign_keys = ON');

        if ($isNew || $path === ':memory:') {
            self::migrate($pdo);
        }

        return $pdo;
    }

    public static function migrate(PDO $pdo): void
    {
        $schema = file_get_contents(dirname(__DIR__) . '/database/schema.sql');
        $pdo->exec($schema);
    }

    /** Used only by tests to reset the singleton between cases if ever needed. */
    public static function reset(): void
    {
        self::$connection = null;
    }
}
