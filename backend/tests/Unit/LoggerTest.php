<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Support\Logger;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class LoggerTest extends TestCase
{
    private string $path;

    protected function setUp(): void
    {
        $this->path = sys_get_temp_dir() . '/logger_' . bin2hex(random_bytes(4)) . '/app.log';
    }

    protected function tearDown(): void
    {
        @unlink($this->path);
        @rmdir(dirname($this->path));
    }

    private function contents(): string
    {
        return is_file($this->path) ? (string) file_get_contents($this->path) : '';
    }

    public function testWritesOneLeveledLinePerRecordAndCreatesTheDirectory(): void
    {
        $logger = new Logger($this->path);

        $logger->info('Service started');
        $logger->warning('Disk getting full');

        $lines = array_values(array_filter(explode("\n", $this->contents())));

        $this->assertCount(2, $lines);
        $this->assertStringContainsString('INFO: Service started', $lines[0]);
        $this->assertStringContainsString('WARNING: Disk getting full', $lines[1]);
        $this->assertMatchesRegularExpression('/^\[\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z\]/', $lines[0]);
    }

    public function testInterpolatesContextPlaceholdersAndAppendsTheContext(): void
    {
        (new Logger($this->path))->warning('Failed login for {email}.', ['email' => 'ada@example.test', 'ip' => '10.0.0.1']);

        $this->assertStringContainsString('Failed login for ada@example.test.', $this->contents());
        $this->assertStringContainsString('{"email":"ada@example.test","ip":"10.0.0.1"}', $this->contents());
    }

    public function testRespectsTheMinimumLevel(): void
    {
        $logger = new Logger($this->path, Logger::WARNING);

        $logger->debug('noisy');
        $logger->info('also noisy');
        $logger->error('this matters');

        $this->assertStringNotContainsString('noisy', $this->contents());
        $this->assertStringContainsString('this matters', $this->contents());
    }

    public function testExceptionLoggingIncludesTheTraceOnlyWhenAsked(): void
    {
        $logger = new Logger($this->path);

        $logger->exception(new RuntimeException('kaboom'));
        $this->assertStringNotContainsString('trace', $this->contents());

        $logger->exception(new RuntimeException('kaboom'), withTrace: true);
        $this->assertStringContainsString('trace', $this->contents());
        $this->assertStringContainsString('RuntimeException', $this->contents());
    }

    public function testTheNullLoggerWritesNothingAndNeverThrows(): void
    {
        Logger::null()->error('should vanish', ['a' => 1]);

        $this->assertSame('', $this->contents());
    }
}
