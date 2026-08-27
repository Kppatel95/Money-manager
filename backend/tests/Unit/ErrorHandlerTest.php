<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Exceptions\ConflictException;
use App\Exceptions\NotFoundException;
use App\Exceptions\RateLimitedException;
use App\Exceptions\ValidationException;
use App\Http\ErrorHandler;
use App\Support\Logger;
use App\Support\Request;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class ErrorHandlerTest extends TestCase
{
    private function handler(bool $debug = false, ?Logger $logger = null): ErrorHandler
    {
        return new ErrorHandler($logger ?? Logger::null(), $debug);
    }

    public function testValidationExceptionBecomes422WithFieldDetails(): void
    {
        $response = $this->handler()->handle(new ValidationException(['amount' => 'Amount is required.']));

        $this->assertSame(422, $response->status);
        $this->assertSame('VALIDATION_ERROR', $response->decoded()['error']['code']);
        $this->assertSame(
            ['amount' => 'Amount is required.'],
            $response->decoded()['error']['details']
        );
    }

    public function testDomainExceptionsMapToTheirStatusCodes(): void
    {
        $cases = [
            [new NotFoundException('Account not found.'), 404, 'NOT_FOUND'],
            [new ConflictException('Budget already exists.'), 409, 'CONFLICT'],
            [new RateLimitedException('Too many attempts.', 900), 429, 'RATE_LIMITED'],
        ];

        foreach ($cases as [$exception, $status, $code]) {
            $response = $this->handler()->handle($exception);
            $this->assertSame($status, $response->status);
            $this->assertSame($code, $response->decoded()['error']['code']);
        }
    }

    public function testRateLimitResponseCarriesRetryAfterHeader(): void
    {
        $response = $this->handler()->handle(new RateLimitedException('Locked out.', 600));

        $this->assertSame('600', $response->headers['Retry-After']);
    }

    public function testUnexpectedExceptionsAreHiddenUnlessDebugging(): void
    {
        $quiet = $this->handler()->handle(new RuntimeException('SQLSTATE gibberish'), Request::create('GET', '/api/v1/accounts'));

        $this->assertSame(500, $quiet->status);
        $this->assertSame('INTERNAL_ERROR', $quiet->decoded()['error']['code']);
        $this->assertStringNotContainsString('gibberish', $quiet->body);

        $loud = $this->handler(debug: true)->handle(new RuntimeException('SQLSTATE gibberish'));

        $this->assertSame(500, $loud->status);
        $this->assertStringContainsString('gibberish', $loud->body);
        $this->assertArrayHasKey('trace', $loud->decoded()['error']['details']);
    }

    public function testUnhandledExceptionsAreLogged(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'log_');
        $handler = $this->handler(logger: new Logger($path));

        $handler->handle(new RuntimeException('boom'), Request::create('GET', '/api/v1/boom'));

        $contents = (string) file_get_contents($path);
        @unlink($path);

        $this->assertStringContainsString('ERROR', $contents);
        $this->assertStringContainsString('boom', $contents);
        $this->assertStringContainsString('/api/v1/boom', $contents);
    }
}
