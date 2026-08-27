<?php

declare(strict_types=1);

namespace App;

use App\Exceptions\MethodNotAllowedException;
use App\Exceptions\NotFoundException;
use App\Support\Request;
use App\Support\Response;

/**
 * A deliberately small front-controller router: map "METHOD /path/{param}" to
 * a callable, pull out path parameters, hand back the Response the handler
 * returned.
 *
 * Handlers receive (Request $request, array $params) and must return a
 * Response. Matching a path but not a method raises 405 rather than 404 so
 * clients can tell "wrong verb" from "wrong URL".
 */
final class Router
{
    /** @var array<int, array{method: string, regex: string, paramNames: array<int, string>, handler: callable}> */
    private array $routes = [];

    private string $prefix = '';

    /**
     * Register a batch of routes under a shared prefix, e.g. '/api/v1'.
     * Nesting works; the prefix is restored afterwards.
     */
    public function group(string $prefix, callable $register): void
    {
        $previous = $this->prefix;
        $this->prefix = rtrim($previous . '/' . trim($prefix, '/'), '/');

        $register($this);

        $this->prefix = $previous;
    }

    public function get(string $pattern, callable $handler): void
    {
        $this->add('GET', $pattern, $handler);
    }

    public function post(string $pattern, callable $handler): void
    {
        $this->add('POST', $pattern, $handler);
    }

    public function put(string $pattern, callable $handler): void
    {
        $this->add('PUT', $pattern, $handler);
    }

    public function patch(string $pattern, callable $handler): void
    {
        $this->add('PATCH', $pattern, $handler);
    }

    public function delete(string $pattern, callable $handler): void
    {
        $this->add('DELETE', $pattern, $handler);
    }

    private function add(string $method, string $pattern, callable $handler): void
    {
        $pattern = $this->prefix . '/' . trim($pattern, '/');
        $pattern = rtrim($pattern, '/');
        $pattern = $pattern === '' ? '/' : $pattern;

        $paramNames = [];
        $regex = preg_replace_callback('#\{(\w+)\}#', function (array $m) use (&$paramNames): string {
            $paramNames[] = $m[1];
            return '([^/]+)';
        }, $pattern);

        $this->routes[] = [
            'method' => $method,
            'regex' => '#^' . $regex . '$#',
            'paramNames' => $paramNames,
            'handler' => $handler,
        ];
    }

    /**
     * @throws NotFoundException|MethodNotAllowedException
     */
    public function dispatch(Request $request): Response
    {
        $pathMatched = false;
        $allowed = [];

        foreach ($this->routes as $route) {
            if (!preg_match($route['regex'], $request->path, $matches)) {
                continue;
            }

            $pathMatched = true;
            $allowed[] = $route['method'];

            if ($route['method'] !== $request->method) {
                continue;
            }

            array_shift($matches);
            $params = $route['paramNames'] === []
                ? []
                : array_combine($route['paramNames'], array_map('urldecode', $matches));

            return ($route['handler'])($request, $params);
        }

        if ($pathMatched) {
            throw new MethodNotAllowedException(
                'Method ' . $request->method . ' is not allowed for this endpoint.',
                ['allowed' => array_values(array_unique($allowed))]
            );
        }

        throw new NotFoundException('No route matches ' . $request->method . ' ' . $request->path . '.');
    }
}
