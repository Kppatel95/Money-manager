<?php

declare(strict_types=1);

namespace App\Support;

use App\Exceptions\BadRequestException;

/**
 * Wraps one incoming HTTP request so nothing below this layer touches the
 * superglobals or php://input. Also constructible by hand (see create()),
 * which is how the integration tests drive the real router.
 */
final class Request
{
    /** @var array<string, mixed> */
    private array $body;

    /** @var array<string, string> lower-cased header name => value */
    private array $headers;

    /**
     * @param array<string, mixed> $query
     * @param array<string, string> $headers
     */
    public function __construct(
        public readonly string $method,
        public readonly string $path,
        private readonly array $query = [],
        array $headers = [],
        string $rawBody = '',
        public readonly ?string $ip = null
    ) {
        $this->headers = [];
        foreach ($headers as $name => $value) {
            $this->headers[strtolower((string) $name)] = (string) $value;
        }

        if (trim($rawBody) === '') {
            $this->body = [];
            return;
        }

        $decoded = json_decode($rawBody, true);

        if (!is_array($decoded)) {
            // Form posts still work; anything else that is not valid JSON is
            // the client's problem and surfaces as a 400.
            parse_str($rawBody, $parsed);
            if ($parsed === [] || array_key_first($parsed) === $rawBody) {
                throw new BadRequestException('Request body must be valid JSON.');
            }
            $decoded = $parsed;
        }

        $this->body = $decoded;
    }

    public static function fromGlobals(): self
    {
        $method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
        $uri = $_SERVER['REQUEST_URI'] ?? '/';
        $path = self::normalisePath(parse_url($uri, PHP_URL_PATH) ?: '/');

        $headers = function_exists('getallheaders') ? (getallheaders() ?: []) : [];
        $raw = file_get_contents('php://input');

        return new self(
            $method,
            $path,
            $_GET,
            $headers,
            $raw === false ? '' : $raw,
            $_SERVER['REMOTE_ADDR'] ?? null
        );
    }

    /**
     * Build a request in code -- used by tests and by the CLI.
     *
     * @param array<string, mixed>|string $body
     * @param array<string, mixed> $query
     * @param array<string, string> $headers
     */
    public static function create(
        string $method,
        string $path,
        array|string $body = [],
        array $query = [],
        array $headers = [],
        ?string $ip = '127.0.0.1'
    ): self {
        if (str_contains($path, '?')) {
            [$path, $queryString] = explode('?', $path, 2);
            parse_str($queryString, $parsedQuery);
            $query = $query + $parsedQuery;
        }

        $raw = is_string($body) ? $body : ($body === [] ? '' : (string) json_encode($body));

        return new self(strtoupper($method), self::normalisePath($path), $query, $headers, $raw, $ip);
    }

    public static function normalisePath(string $path): string
    {
        $path = '/' . trim($path, '/');

        return $path === '//' ? '/' : $path;
    }

    public function input(string $key, mixed $default = null): mixed
    {
        return $this->body[$key] ?? $default;
    }

    public function has(string $key): bool
    {
        return array_key_exists($key, $this->body);
    }

    /** @return array<string, mixed> */
    public function all(): array
    {
        return $this->body;
    }

    public function query(string $key, mixed $default = null): mixed
    {
        $value = $this->query[$key] ?? null;

        return $value === null || $value === '' ? $default : $value;
    }

    public function queryInt(string $key, ?int $default = null): ?int
    {
        $value = $this->query($key);

        return $value === null ? $default : (int) $value;
    }

    /** @return array<string, mixed> */
    public function queryAll(): array
    {
        return $this->query;
    }

    public function header(string $name): ?string
    {
        return $this->headers[strtolower($name)] ?? null;
    }

    public function bearerToken(): ?string
    {
        $header = $this->header('authorization');

        if ($header === null || !str_starts_with($header, 'Bearer ')) {
            return null;
        }

        $token = trim(substr($header, 7));

        return $token === '' ? null : $token;
    }

    public function clientIp(): string
    {
        return $this->ip ?? 'unknown';
    }
}
