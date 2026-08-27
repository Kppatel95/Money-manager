<?php

declare(strict_types=1);

namespace App;

use App\Migration\Migrator;
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
            $path = Env::get('DB_PATH', dirname(__DIR__) . '/database/finance.sqlite');
            self::$connection = self::connect($path);
        }

        return self::$connection;
    }

    /**
     * Opens (and, unless told otherwise, migrates) a SQLite database.
     *
     * Migrations run on every boot: the ledger check is a single indexed read
     * when there is nothing to do, and it means a freshly cloned checkout or a
     * database left behind by an older release is always usable. Deployments
     * that would rather gate this explicitly can run `php bin/migrate.php`
     * and pass $migrate = false here.
     */
    public static function connect(string $path, bool $migrate = true): PDO
    {
        $pdo = new PDO('sqlite:' . $path);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        $pdo->exec('PRAGMA foreign_keys = ON');

        if ($migrate) {
            (new Migrator($pdo, Migrator::defaultPath()))->migrate();
        }

        return $pdo;
    }

    /** Used only by tests to reset the singleton between cases if ever needed. */
    public static function reset(): void
    {
        self::$connection = null;
    }
}
