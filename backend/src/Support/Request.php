<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Wraps the raw superglobals for a single incoming request so controllers
 * don't reach into $_SERVER / php://input directly.
 */
final class Request
{
    private array $body;

    public function __construct(
        public readonly string $method,
        public readonly string $path,
        private readonly array $query,
        private readonly array $headers,
        string $rawBody
    ) {
        $decoded = json_decode($rawBody, true);
        $this->body = is_array($decoded) ? $decoded : [];
    }

    public static function fromGlobals(): self
    {
        $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
        $uri = $_SERVER['REQUEST_URI'] ?? '/';
        $path = rtrim(parse_url($uri, PHP_URL_PATH) ?: '/', '/');
        $path = $path === '' ? '/' : $path;

        $headers = function_exists('getallheaders') ? getallheaders() : [];
        $raw = file_get_contents('php://input') ?: '';

        return new self($method, $path, $_GET, $headers, $raw);
    }

    public function input(string $key, mixed $default = null): mixed
    {
        return $this->body[$key] ?? $default;
    }

    public function all(): array
    {
        return $this->body;
    }

    public function query(string $key, mixed $default = null): mixed
    {
        return $this->query[$key] ?? $default;
    }

    public function bearerToken(): ?string
    {
        foreach ($this->headers as $name => $value) {
            if (strtolower($name) === 'authorization' && str_starts_with($value, 'Bearer ')) {
                return substr($value, 7);
            }
        }

        return null;
    }
}
