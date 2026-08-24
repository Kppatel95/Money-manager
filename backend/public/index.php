<?php

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

use App\Auth\JwtService;
use App\Controllers\AuthController;
use App\Database;
use App\Repositories\UserRepository;
use App\Router;
use App\Support\Env;
use App\Support\Request;
use App\Support\Response;

Env::load(dirname(__DIR__) . '/.env');

// Allow the Vite dev server (and any other origin, for a portfolio demo) to
// call the API. A real production deployment would lock this down to a
// specific origin.
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Headers: Content-Type, Authorization');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') {
    http_response_code(204);
    exit;
}

$pdo = Database::connection();
$users = new UserRepository($pdo);
$jwt = new JwtService();

$authController = new AuthController($users, $jwt);

$router = new Router();

$router->post('/api/register', fn (Request $r) => $authController->register($r));
$router->post('/api/login', fn (Request $r) => $authController->login($r));

try {
    $router->dispatch(Request::fromGlobals());
} catch (\Throwable $e) {
    $debug = Env::get('APP_DEBUG', 'false') === 'true';
    Response::error(
        'Internal server error.',
        500,
        $debug ? ['exception' => $e->getMessage()] : []
    );
}
