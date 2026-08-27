<?php

declare(strict_types=1);

namespace App\Http;

use App\Exceptions\HttpException;
use App\Support\Logger;
use App\Support\Request;
use App\Support\Response;
use Throwable;

/**
 * The single place where a thrown thing becomes an HTTP status code.
 *
 * Every error response the API emits has the same envelope:
 *   {"error": {"code": "...", "message": "...", "details": {...}}}
 */
final class ErrorHandler
{
    public function __construct(
        private readonly Logger $logger,
        private readonly bool $debug = false
    ) {
    }

    public function handle(Throwable $e, ?Request $request = null): Response
    {
        if ($e instanceof HttpException) {
            if ($e->statusCode() >= 500) {
                $this->logger->exception($e, $this->debug, $this->requestContext($request));
            }

            return Response::error(
                $e->errorCode(),
                $e->getMessage(),
                $e->statusCode(),
                $e->details(),
                $e->headers()
            );
        }

        $this->logger->exception($e, true, $this->requestContext($request));

        $details = $this->debug
            ? [
                'exception' => $e::class,
                'file' => $e->getFile() . ':' . $e->getLine(),
                'trace' => explode("\n", $e->getTraceAsString()),
            ]
            : [];

        return Response::error(
            'INTERNAL_ERROR',
            $this->debug ? $e->getMessage() : 'An unexpected error occurred.',
            500,
            $details
        );
    }

    /** @return array<string, mixed> */
    private function requestContext(?Request $request): array
    {
        if ($request === null) {
            return [];
        }

        return ['method' => $request->method, 'path' => $request->path];
    }
}
