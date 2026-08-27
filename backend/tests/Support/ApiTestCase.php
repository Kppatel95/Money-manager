<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Database;
use App\Http\Application;
use App\Support\Logger;
use App\Support\Request;
use App\Support\Response;
use PDO;
use PHPUnit\Framework\TestCase;

/**
 * Base class for tests that drive the real router.
 *
 * Every test gets its own temporary SQLite file, migrated from scratch, so
 * cases cannot see each other's rows and no development database is ever
 * touched.
 */
abstract class ApiTestCase extends TestCase
{
    protected string $dbPath;
    protected PDO $pdo;
    protected Application $app;

    protected function setUp(): void
    {
        $this->dbPath = tempnam(sys_get_temp_dir(), 'apitest_') . '.sqlite';
        $this->pdo = Database::connect($this->dbPath);
        $this->app = Application::boot($this->pdo, Logger::null(), debug: false);
    }

    protected function tearDown(): void
    {
        @unlink($this->dbPath);
    }

    /** @param array<string, mixed>|string $body */
    protected function request(string $method, string $path, array|string $body = [], ?string $token = null): Response
    {
        $headers = ['Content-Type' => 'application/json'];

        if ($token !== null) {
            $headers['Authorization'] = 'Bearer ' . $token;
        }

        return $this->app->handle(Request::create($method, $path, $body, [], $headers));
    }

    /** @param array<string, mixed> $body */
    protected function get(string $path, ?string $token = null): Response
    {
        return $this->request('GET', $path, [], $token);
    }

    /** @param array<string, mixed> $body */
    protected function post(string $path, array $body = [], ?string $token = null): Response
    {
        return $this->request('POST', $path, $body, $token);
    }

    /** @param array<string, mixed> $body */
    protected function put(string $path, array $body = [], ?string $token = null): Response
    {
        return $this->request('PUT', $path, $body, $token);
    }

    protected function delete(string $path, ?string $token = null): Response
    {
        return $this->request('DELETE', $path, [], $token);
    }

    /**
     * Registers a user through the public API and returns the useful bits.
     *
     * @return array{id: int, token: string, refresh_token: string, email: string}
     */
    protected function registerUser(string $email = 'user@example.test', string $password = 'password123'): array
    {
        $response = $this->post('/api/v1/auth/register', [
            'name' => explode('@', $email)[0],
            'email' => $email,
            'password' => $password,
        ]);

        if ($response->status !== 201) {
            $this->fail('Registration failed: ' . $response->body);
        }

        $data = $response->decoded()['data'];

        return [
            'id' => (int) $data['user']['id'],
            'token' => $data['access_token'],
            'refresh_token' => $data['refresh_token'],
            'email' => $email,
        ];
    }

    /** Convenience: an account belonging to the given user, created via the API. */
    protected function createAccount(string $token, string $name = 'Checking', string $type = 'bank', float $initial = 0.0): array
    {
        $response = $this->post('/api/v1/accounts', [
            'name' => $name,
            'type' => $type,
            'initial_balance' => $initial,
        ], $token);

        if ($response->status !== 201) {
            $this->fail('Account creation failed: ' . $response->body);
        }

        return $response->decoded()['data'];
    }

    /** The id of a system category with the given name. */
    protected function categoryId(string $token, string $name): int
    {
        foreach ($this->get('/api/v1/categories', $token)->decoded()['data'] as $category) {
            if ($category['name'] === $name) {
                return (int) $category['id'];
            }
        }

        $this->fail("No category named {$name}.");
    }

    protected function assertStatus(int $expected, Response $response, string $message = ''): void
    {
        $this->assertSame(
            $expected,
            $response->status,
            ($message !== '' ? $message . ' ' : '') . 'Body: ' . $response->body
        );
    }

    protected function assertErrorCode(string $expected, Response $response): void
    {
        $this->assertSame($expected, $response->decoded()['error']['code'] ?? null, 'Body: ' . $response->body);
    }
}
