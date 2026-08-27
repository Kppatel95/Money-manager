<?php

declare(strict_types=1);

namespace App\Support;

/**
 * An HTTP response as a value object.
 *
 * Handlers *return* one of these instead of echoing and exiting, which is what
 * makes the whole stack testable: an integration test can dispatch a Request
 * through the real router and assert on the returned status and payload
 * without output buffering or a live web server.
 */
final class Response
{
    /**
     * @param array<string, string> $headers
     */
    private function __construct(
        public readonly int $status,
        public readonly string $body,
        public readonly array $headers = []
    ) {
    }

    /**
     * @param array<string|int, mixed> $data
     * @param array<string, string> $headers
     */
    public static function json(array $data, int $status = 200, array $headers = []): self
    {
        return new self(
            $status,
            (string) json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            $headers + ['Content-Type' => 'application/json']
        );
    }

    /** 200 with the payload wrapped in the standard `data` envelope. */
    public static function data(mixed $data, int $status = 200, array $meta = []): self
    {
        $payload = ['data' => $data];

        if ($meta !== []) {
            $payload['meta'] = $meta;
        }

        return self::json($payload, $status);
    }

    public static function created(mixed $data): self
    {
        return self::data($data, 201);
    }

    public static function noContent(): self
    {
        return new self(204, '', []);
    }

    /** @param array<string, string> $headers */
    public static function raw(string $body, int $status = 200, array $headers = []): self
    {
        return new self($status, $body, $headers);
    }

    /** @param array<string, mixed> $details */
    public static function error(string $code, string $message, int $status, array $details = [], array $headers = []): self
    {
        $error = ['code' => $code, 'message' => $message];

        if ($details !== []) {
            $error['details'] = $details;
        }

        return self::json(['error' => $error], $status, $headers);
    }

    public function withHeader(string $name, string $value): self
    {
        return new self($this->status, $this->body, [$name => $value] + $this->headers);
    }

    /** Decoded body, for tests and for callers that want to inspect a response. */
    public function decoded(): array
    {
        $decoded = json_decode($this->body, true);

        return is_array($decoded) ? $decoded : [];
    }

    public function send(): void
    {
        http_response_code($this->status);

        foreach ($this->headers as $name => $value) {
            header($name . ': ' . $value);
        }

        if ($this->body !== '') {
            echo $this->body;
        }
    }
}
