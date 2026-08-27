<?php

declare(strict_types=1);

namespace App\Http;

use App\Auth\Authenticate;
use App\Auth\JwtService;
use App\Controllers\AuthController;
use App\Controllers\ExpenseController;
use App\Repositories\ExpenseRepository;
use App\Repositories\UserRepository;
use App\Router;
use App\Support\Logger;
use App\Support\Request;
use App\Support\Response;
use PDO;
use Throwable;

/**
 * Composition root: builds the object graph, registers the routes, and turns a
 * Request into a Response. public/index.php only has to send the result, and
 * the integration tests can drive the exact same code path in-process.
 *
 * There is no DI container on purpose -- with this many collaborators, wiring
 * them by hand in one readable method is easier to follow than configuring a
 * container, and it keeps constructor injection honest everywhere else.
 */
final class Application
{
    private Router $router;

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
        $auth = new Authenticate($jwt, $users);

        $this->registerLegacyRoutes($users, $jwt, $auth);
    }

    /**
     * The original single-table expense API. Still mounted under /api so the
     * existing frontend keeps working while /api/v1 is built out.
     */
    private function registerLegacyRoutes(UserRepository $users, JwtService $jwt, Authenticate $auth): void
    {
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
