#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Materialises every due recurring transaction for every user.
 *
 * The API also catches schedules up on the first authenticated request (see
 * App\Http\Application::catchUpRecurring), which is what makes the demo work
 * without a scheduler. Where a real scheduler exists, run this nightly
 * instead and the request-time hook becomes a no-op:
 *
 *   0 1 * * *  cd /srv/finance/backend && php bin/run-recurring.php
 */

require dirname(__DIR__) . '/vendor/autoload.php';

use App\Database;
use App\Repositories\AccountRepository;
use App\Repositories\CategoryRepository;
use App\Repositories\RecurringTransactionRepository;
use App\Repositories\TransactionRepository;
use App\Repositories\UserRepository;
use App\Services\AccountService;
use App\Services\CategoryService;
use App\Services\RecurringTransactionService;
use App\Support\Env;
use App\Support\Logger;

Env::load(dirname(__DIR__) . '/.env');

$pdo = Database::connection();
$logger = Logger::fromEnv();

$service = new RecurringTransactionService(
    new RecurringTransactionRepository($pdo),
    new TransactionRepository($pdo),
    new AccountService(new AccountRepository($pdo)),
    new CategoryService(new CategoryRepository($pdo)),
    $logger
);

$today = $argv[1] ?? date('Y-m-d');
$total = 0;

foreach ((new UserRepository($pdo))->allIds() as $userId) {
    $created = $service->runDue($userId, $today);
    $total += count($created);

    if ($created !== []) {
        echo "user {$userId}: created " . count($created) . " transaction(s)\n";
    }
}

echo "Done. {$total} transaction(s) created for {$today}.\n";
