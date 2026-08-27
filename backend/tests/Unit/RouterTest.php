<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Exceptions\MethodNotAllowedException;
use App\Exceptions\NotFoundException;
use App\Router;
use App\Support\Request;
use App\Support\Response;
use PHPUnit\Framework\TestCase;

final class RouterTest extends TestCase
{
    public function testMatchesAPrefixedRouteAndReturnsTheHandlerResponse(): void
    {
        $router = new Router();
        $router->group('/api/v1', function (Router $r): void {
            $r->get('/accounts', fn () => Response::json(['data' => []]));
        });

        $response = $router->dispatch(Request::create('GET', '/api/v1/accounts'));

        $this->assertSame(200, $response->status);
        $this->assertSame(['data' => []], $response->decoded());
    }

    public function testExtractsPathParameters(): void
    {
        $router = new Router();
        $router->group('/api/v1', function (Router $r): void {
            $r->put('/transactions/{id}', fn (Request $q, array $p) => Response::json(['id' => $p['id']]));
        });

        $response = $router->dispatch(Request::create('PUT', '/api/v1/transactions/42'));

        $this->assertSame(['id' => '42'], $response->decoded());
    }

    public function testUnknownPathThrowsNotFound(): void
    {
        $router = new Router();
        $router->get('/api/v1/accounts', fn () => Response::noContent());

        $this->expectException(NotFoundException::class);
        $router->dispatch(Request::create('GET', '/api/v1/nope'));
    }

    public function testKnownPathWithWrongVerbThrowsMethodNotAllowed(): void
    {
        $router = new Router();
        $router->get('/api/v1/accounts', fn () => Response::noContent());

        $this->expectException(MethodNotAllowedException::class);
        $router->dispatch(Request::create('DELETE', '/api/v1/accounts'));
    }

    public function testTrailingSlashesAreIgnored(): void
    {
        $router = new Router();
        $router->get('/api/v1/accounts', fn () => Response::json(['ok' => true]));

        $response = $router->dispatch(Request::create('GET', '/api/v1/accounts/'));

        $this->assertSame(['ok' => true], $response->decoded());
    }

    public function testQueryStringIsParsedOutOfThePath(): void
    {
        $router = new Router();
        $router->get('/api/v1/transactions', fn (Request $q) => Response::json([
            'page' => $q->queryInt('page'),
            'search' => $q->query('search'),
        ]));

        $response = $router->dispatch(Request::create('GET', '/api/v1/transactions?page=3&search=coffee'));

        $this->assertSame(['page' => 3, 'search' => 'coffee'], $response->decoded());
    }
}
