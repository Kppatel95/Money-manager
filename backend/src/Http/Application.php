<?php

declare(strict_types=1);

namespace App\Http;

use App\Auth\Authenticate;
use App\Auth\JwtService;
use App\Controllers\AccountController;
use App\Controllers\AuthController;
use App\Controllers\BudgetController;
use App\Controllers\CategoryController;
use App\Controllers\DashboardController;
use App\Controllers\ExpenseController;
use App\Controllers\RecurringTransactionController;
use App\Controllers\TransactionController;
use App\Repositories\AccountRepository;
use App\Repositories\BudgetRepository;
use App\Repositories\CategoryRepository;
use App\Repositories\ExpenseRepository;
use App\Repositories\LoginAttemptRepository;
use App\Repositories\RecurringTransactionRepository;
use App\Repositories\RefreshTokenRepository;
use App\Repositories\TransactionRepository;
use App\Repositories\UserRepository;
use App\Router;
use App\Services\AccountService;
use App\Services\AuthService;
use App\Services\BudgetService;
use App\Services\CategoryService;
use App\Services\DashboardService;
use App\Services\RecurringTransactionService;
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

    private RecurringTransactionService $recurring;

    /** Recurring schedules are caught up once per request, not once per route. */
    private bool $recurringChecked = false;

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

        $auth = new AuthService(
            $users,
            new RefreshTokenRepository($this->pdo),
            new LoginAttemptRepository($this->pdo),
            $jwt,
            $this->logger
        );

        $accountRepository = new AccountRepository($this->pdo);
        $accounts = new AccountService($accountRepository);
        $categories = new CategoryService(new CategoryRepository($this->pdo));

        $transactionRepository = new TransactionRepository($this->pdo);
        $transactions = new TransactionService($transactionRepository, $accounts, $categories);
        $budgets = new BudgetService(new BudgetRepository($this->pdo), $transactionRepository, $categories);
        $this->recurring = new RecurringTransactionService(
            new RecurringTransactionRepository($this->pdo),
            $transactionRepository,
            $accounts,
            $categories,
            $this->logger
        );

        $dashboard = new DashboardService($accountRepository, $transactionRepository, $accounts, $budgets);

        $accountController = new AccountController($accounts);
        $categoryController = new CategoryController($categories);
        $transactionController = new TransactionController($transactions);
        $budgetController = new BudgetController($budgets);
        $recurringController = new RecurringTransactionController($this->recurring);
        $authController = new AuthController($auth, $users);
        $dashboardController = new DashboardController($dashboard);

        $this->router->group('/api/v1', function (Router $r) use (
            $accountController,
            $categoryController,
            $transactionController,
            $budgetController,
            $recurringController,
            $authController,
            $dashboardController
        ): void {
            $r->post('/auth/register', fn (Request $q) => $authController->register($q));
            $r->post('/auth/login', fn (Request $q) => $authController->login($q));
            $r->post('/auth/refresh', fn (Request $q) => $authController->refresh($q));
            $r->post('/auth/logout', $this->authed([$authController, 'logout']));
            $r->get('/auth/me', $this->authed([$authController, 'me']));

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

            $r->get('/budgets', $this->authed([$budgetController, 'index']));
            $r->post('/budgets', $this->authed([$budgetController, 'store']));
            $r->put('/budgets/{id}', $this->authed([$budgetController, 'update']));
            $r->delete('/budgets/{id}', $this->authed([$budgetController, 'destroy']));

            $r->get('/recurring-transactions', $this->authed([$recurringController, 'index']));
            $r->post('/recurring-transactions', $this->authed([$recurringController, 'store']));
            $r->put('/recurring-transactions/{id}', $this->authed([$recurringController, 'update']));
            $r->delete('/recurring-transactions/{id}', $this->authed([$recurringController, 'destroy']));

            $r->get('/dashboard/summary', $this->authed([$dashboardController, 'summary']));
        });

        $this->registerLegacyRoutes();
    }

    /**
     * Wraps a controller method so it only runs for an authenticated caller,
     * and receives the caller's id rather than digging it out of the request.
     */
    private function authed(callable $handler): callable
    {
        return function (Request $request, array $params = []) use ($handler): Response {
            $user = $this->authenticate->handle($request);
            $userId = (int) $user['id'];

            $this->catchUpRecurring($userId);

            return $handler($request, $userId, $params);
        };
    }

    /**
     * Materialises any due recurring transactions for this user.
     *
     * There is no cron in this deployment, so the work rides along with the
     * first authenticated request of the request cycle. The cost is one
     * indexed lookup that returns nothing in the overwhelming majority of
     * requests; the payoff is that recurring entries appear without any
     * scheduler to install, and a user who has not opened the app for a month
     * still gets a correct ledger because runDue() catches up every missed
     * occurrence. The obvious limitation: nothing happens while nobody logs
     * in. If that ever matters, the same call belongs behind a real cron
     * hitting `php bin/run-recurring.php`, and this hook can go away.
     */
    private function catchUpRecurring(int $userId): void
    {
        if ($this->recurringChecked) {
            return;
        }

        $this->recurringChecked = true;

        try {
            $this->recurring->runDue($userId);
        } catch (Throwable $e) {
            // A broken schedule must not take down an unrelated request.
            $this->logger->exception($e, false, ['hook' => 'recurring', 'user_id' => $userId]);
        }
    }

    /**
     * The original single-table expense API. Still mounted under /api so the
     * existing frontend keeps working while /api/v1 is built out.
     */
    private function registerLegacyRoutes(): void
    {
        $auth = $this->authenticate;
        $expenses = new ExpenseController(new ExpenseRepository($this->pdo));

        $this->router->group('/api', function (Router $r) use ($expenses, $auth): void {
            $r->get('/expenses', fn (Request $q) => $expenses->index($q, $auth->handle($q)));
            $r->post('/expenses', fn (Request $q) => $expenses->store($q, $auth->handle($q)));
            $r->put('/expenses/{id}', fn (Request $q, array $p) => $expenses->update($q, $auth->handle($q), $p['id']));
            $r->delete('/expenses/{id}', fn (Request $q, array $p) => $expenses->destroy($q, $auth->handle($q), $p['id']));
            $r->get('/summary', fn (Request $q) => $expenses->summary($q, $auth->handle($q)));
        });
    }
}
