<?php

declare(strict_types=1);

namespace App\Http;

use App\Auth\Authenticate;
use App\Auth\JwtService;
use App\Controllers\AccountController;
use App\Controllers\AuthController;
use App\Controllers\CategoryController;
use App\Controllers\ExpenseController;
use App\Controllers\TransactionController;
use App\Repositories\AccountRepository;
use App\Repositories\CategoryRepository;
use App\Repositories\ExpenseRepository;
use App\Repositories\TransactionRepository;
use App\Repositories\UserRepository;
use App\Router;
use App\Services\AccountService;
use App\Services\CategoryService;
use App\Services\TransactionService;
use App\Support\Logger;
use App\Support\Request;
use App\Support\Response;
use PDO;
use Throwable;

/**
 * Composition root: builds the object graph, registers the routes, and turns a
 * Request into a Response. public/index.php only has to send the result, and
 * the integration tests drive the exact same code path in-process.
 *
 * There is no DI container on purpose -- wiring these collaborators by hand in
 * one readable method is easier to follow than configuring a container, and it
 * keeps constructor injection honest everywhere else.
 */
final class Application
{
    private Router $router;

    private Authenticate $authenticate;

    private function __construct(
        private readonly PDO $pdo,
        private readonly Logger $logger,
        private readonly ErrorHandler $errors
    ) {
        $this->router = new Router();
        $this->registerRoutes();
    }

    public static function boot(PDO $pdo, ?Logger $logger = null, bool $debug = false): self
    {
        $logger ??= Logger::null();

        return new self($pdo, $logger, new ErrorHandler($logger, $debug));
    }

    public function handle(Request $request): Response
    {
        try {
            return $this->router->dispatch($request);
        } catch (Throwable $e) {
            return $this->errors->handle($e, $request);
        }
    }

    public function logger(): Logger
    {
        return $this->logger;
    }

    private function registerRoutes(): void
    {
        $users = new UserRepository($this->pdo);
        $jwt = new JwtService();
        $this->authenticate = new Authenticate($jwt, $users);

        $accounts = new AccountService(new AccountRepository($this->pdo));
        $categories = new CategoryService(new CategoryRepository($this->pdo));

        $transactions = new TransactionService(
            new TransactionRepository($this->pdo),
            $accounts,
            $categories
        );

        $accountController = new AccountController($accounts);
        $categoryController = new CategoryController($categories);
        $transactionController = new TransactionController($transactions);

        $this->router->group('/api/v1', function (Router $r) use (
            $accountController,
            $categoryController,
            $transactionController
        ): void {
            $r->get('/accounts', $this->authed([$accountController, 'index']));
            $r->post('/accounts', $this->authed([$accountController, 'store']));
            $r->get('/accounts/{id}', $this->authed([$accountController, 'show']));
            $r->put('/accounts/{id}', $this->authed([$accountController, 'update']));
            $r->delete('/accounts/{id}', $this->authed([$accountController, 'destroy']));
            $r->get('/accounts/{id}/balance', $this->authed([$accountController, 'balance']));

            $r->get('/categories', $this->authed([$categoryController, 'index']));
            $r->post('/categories', $this->authed([$categoryController, 'store']));
            $r->put('/categories/{id}', $this->authed([$categoryController, 'update']));
            $r->delete('/categories/{id}', $this->authed([$categoryController, 'destroy']));

            $r->get('/transactions', $this->authed([$transactionController, 'index']));
            $r->post('/transactions', $this->authed([$transactionController, 'store']));
            $r->get('/transactions/{id}', $this->authed([$transactionController, 'show']));
            $r->put('/transactions/{id}', $this->authed([$transactionController, 'update']));
            $r->delete('/transactions/{id}', $this->authed([$transactionController, 'destroy']));
        });

        $this->registerLegacyRoutes($users, $jwt);
    }

    /**
     * Wraps a controller method so it only runs for an authenticated caller,
     * and receives the caller's id rather than digging it out of the request.
     */
    private function authed(callable $handler): callable
    {
        return function (Request $request, array $params = []) use ($handler): Response {
            $user = $this->authenticate->handle($request);

            return $handler($request, (int) $user['id'], $params);
        };
    }

    /**
     * The original single-table expense API. Still mounted under /api so the
     * existing frontend keeps working while /api/v1 is built out.
     */
    private function registerLegacyRoutes(UserRepository $users, JwtService $jwt): void
    {
        $auth = $this->authenticate;
        $authController = new AuthController($users, $jwt);
        $expenses = new ExpenseController(new ExpenseRepository($this->pdo));

        $this->router->group('/api', function (Router $r) use ($authController, $expenses, $auth): void {
            $r->post('/register', fn (Request $q) => $authController->register($q));
            $r->post('/login', fn (Request $q) => $authController->login($q));

            $r->get('/expenses', fn (Request $q) => $expenses->index($q, $auth->handle($q)));
            $r->post('/expenses', fn (Request $q) => $expenses->store($q, $auth->handle($q)));
            $r->put('/expenses/{id}', fn (Request $q, array $p) => $expenses->update($q, $auth->handle($q), $p['id']));
            $r->delete('/expenses/{id}', fn (Request $q, array $p) => $expenses->destroy($q, $auth->handle($q), $p['id']));
            $r->get('/summary', fn (Request $q) => $expenses->summary($q, $auth->handle($q)));
        });
    }
}
