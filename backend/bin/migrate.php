#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Applies any pending database migrations.
 *
 *   php bin/migrate.php            apply pending migrations
 *   php bin/migrate.php --status   list applied and pending migrations
 *
 * This is the intended way to migrate in production: run it as part of the
 * deploy, before the new code starts serving traffic.
 */

require dirname(__DIR__) . '/vendor/autoload.php';

use App\Migration\Migrator;
use App\Support\Env;

Env::load(dirname(__DIR__) . '/.env');

$path = Env::get('DB_PATH', dirname(__DIR__) . '/database/finance.sqlite');
$dir = dirname($path);

if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
    fwrite(STDERR, "Cannot create database directory {$dir}\n");
    exit(1);
}

$pdo = App\Database::connect($path, migrate: false);
$migrator = new Migrator($pdo, Migrator::defaultPath());

try {
    if (in_array('--status', $argv, true)) {
        echo "Applied:\n";
        foreach ($migrator->appliedMigrations() as $name) {
            echo "  [x] {$name}\n";
        }
        echo "Pending:\n";
        $pending = $migrator->pending();
        foreach ($pending as $name) {
            echo "  [ ] {$name}\n";
        }
        if ($pending === []) {
            echo "  (none)\n";
        }
        exit(0);
    }

    echo "Database: {$path}\n";
    $ran = $migrator->migrate();

    if ($ran === []) {
        echo "Nothing to migrate. Schema is up to date.\n";
        exit(0);
    }

    foreach ($ran as $name) {
        echo "Migrated: {$name}\n";
    }

    echo count($ran) . " migration(s) applied.\n";
    exit(0);
} catch (Throwable $e) {
    fwrite(STDERR, 'Migration failed: ' . $e->getMessage() . "\n");
    exit(1);
}
