<?php

declare(strict_types=1);

namespace App;

use App\Support\Request;
use App\Support\Response;

/**
 * A deliberately small front-controller router. No framework -- just enough
 * to map "METHOD /path/{param}" to a callable and extract path parameters.
 */
final class Router
{
    /** @var array<int, array{method: string, pattern: string, paramNames: array<int, string>, handler: callable}> */
    private array $routes = [];

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

    public function delete(string $pattern, callable $handler): void
    {
        $this->add('DELETE', $pattern, $handler);
    }

    private function add(string $method, string $pattern, callable $handler): void
    {
        $paramNames = [];
        $regex = preg_replace_callback('#\{(\w+)\}#', function ($m) use (&$paramNames) {
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

    public function dispatch(Request $request): void
    {
        $matchedPath = false;

        foreach ($this->routes as $route) {
            if (!preg_match($route['regex'], $request->path, $matches)) {
                continue;
            }

            $matchedPath = true;

            if ($route['method'] !== $request->method) {
                continue;
            }

            array_shift($matches);
            $params = array_combine($route['paramNames'], $matches);

            ($route['handler'])($request, $params);
            return;
        }

        if ($matchedPath) {
            Response::error('Method not allowed', 405);
        }

        Response::error('Not found', 404);
    }
}
