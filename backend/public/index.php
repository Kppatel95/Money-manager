<?php

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

use App\Auth\Authenticate;
use App\Auth\JwtService;
use App\Controllers\AuthController;
use App\Controllers\ExpenseController;
use App\Database;
use App\Repositories\ExpenseRepository;
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
$expenses = new ExpenseRepository($pdo);
$jwt = new JwtService();
$auth = new Authenticate($jwt, $users);

$authController = new AuthController($users, $jwt);
$expenseController = new ExpenseController($expenses);

$router = new Router();

$router->post('/api/register', fn (Request $r) => $authController->register($r));
$router->post('/api/login', fn (Request $r) => $authController->login($r));

$router->get('/api/expenses', function (Request $r) use ($auth, $expenseController) {
    $expenseController->index($r, $auth->handle($r));
});
$router->post('/api/expenses', function (Request $r) use ($auth, $expenseController) {
    $expenseController->store($r, $auth->handle($r));
});
$router->put('/api/expenses/{id}', function (Request $r, array $p) use ($auth, $expenseController) {
    $expenseController->update($r, $auth->handle($r), $p['id']);
});
$router->delete('/api/expenses/{id}', function (Request $r, array $p) use ($auth, $expenseController) {
    $expenseController->destroy($r, $auth->handle($r), $p['id']);
});

$router->get('/api/summary', function (Request $r) use ($auth, $expenseController) {
    $expenseController->summary($r, $auth->handle($r));
});

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
