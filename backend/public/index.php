<?php

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

use App\Database;
use App\Http\Application;
use App\Http\ErrorHandler;
use App\Support\Env;
use App\Support\Logger;
use App\Support\Request;

Env::load(dirname(__DIR__) . '/.env');

// Wide open for a portfolio demo so the Vite dev server can call the API from
// any port. A real deployment would pin this to a known origin.
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Headers: Content-Type, Authorization');
header('Access-Control-Allow-Methods: GET, POST, PUT, PATCH, DELETE, OPTIONS');
header('Access-Control-Max-Age: 86400');

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') {
    http_response_code(204);
    exit;
}

$debug = Env::get('APP_DEBUG', 'false') === 'true';
$logger = Logger::fromEnv();

try {
    $pdo = Database::connection();
    $app = Application::boot($pdo, $logger, $debug);
    $app->handle(Request::fromGlobals())->send();
} catch (Throwable $e) {
    // Anything thrown before the application exists (bad DB path, failed
    // migration) still has to come back as the standard error envelope.
    (new ErrorHandler($logger, $debug))->handle($e)->send();
}
