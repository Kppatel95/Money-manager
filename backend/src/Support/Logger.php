<?php

declare(strict_types=1);

namespace App\Support;

use Stringable;
use Throwable;

/**
 * A small PSR-3-shaped logger: the level methods and the {placeholder}
 * interpolation everyone expects, writing one line per record to a file.
 *
 * Hand-rolled on purpose. The app needs "append a level, a message and some
 * context to a file"; a logging framework would add a dependency, a config
 * format and a handler/formatter hierarchy for behaviour that fits in a
 * hundred lines. If this ever needs syslog, sampling or structured shipping,
 * swapping in a PSR-3 implementation is a constructor change, because nothing
 * outside this class knows how the writing happens.
 */
final class Logger
{
    public const DEBUG = 'debug';
    public const INFO = 'info';
    public const WARNING = 'warning';
    public const ERROR = 'error';

    private const SEVERITY = [
        self::DEBUG => 10,
        self::INFO => 20,
        self::WARNING => 30,
        self::ERROR => 40,
    ];

    public function __construct(
        private readonly ?string $path,
        private readonly string $minimumLevel = self::DEBUG
    ) {
    }

    public static function fromEnv(): self
    {
        $default = dirname(__DIR__, 2) . '/storage/logs/app.log';

        return new self(
            Env::get('LOG_PATH', $default),
            Env::get('LOG_LEVEL', self::DEBUG) ?? self::DEBUG
        );
    }

    /** A logger that swallows everything -- handy in unit tests. */
    public static function null(): self
    {
        return new self(null);
    }

    /** @param array<string, mixed> $context */
    public function debug(string|Stringable $message, array $context = []): void
    {
        $this->log(self::DEBUG, $message, $context);
    }

    /** @param array<string, mixed> $context */
    public function info(string|Stringable $message, array $context = []): void
    {
        $this->log(self::INFO, $message, $context);
    }

    /** @param array<string, mixed> $context */
    public function warning(string|Stringable $message, array $context = []): void
    {
        $this->log(self::WARNING, $message, $context);
    }

    /** @param array<string, mixed> $context */
    public function error(string|Stringable $message, array $context = []): void
    {
        $this->log(self::ERROR, $message, $context);
    }

    /** @param array<string, mixed> $context */
    public function log(string $level, string|Stringable $message, array $context = []): void
    {
        if ($this->path === null || !$this->shouldLog($level)) {
            return;
        }

        $line = sprintf(
            '[%s] %s: %s%s',
            gmdate('Y-m-d\TH:i:s\Z'),
            strtoupper($level),
            $this->interpolate((string) $message, $context),
            $context === [] ? '' : ' ' . $this->encodeContext($context)
        );

        $this->write($line . PHP_EOL);
    }

    /** Convenience for the error handler: log a throwable with its class and origin. */
    public function exception(Throwable $e, bool $withTrace = false, array $context = []): void
    {
        $this->error($e->getMessage(), $context + array_filter([
            'exception' => $e::class,
            'file' => $e->getFile() . ':' . $e->getLine(),
            'trace' => $withTrace ? $e->getTraceAsString() : null,
        ], static fn ($v) => $v !== null));
    }

    private function shouldLog(string $level): bool
    {
        $threshold = self::SEVERITY[$this->minimumLevel] ?? 0;

        return (self::SEVERITY[$level] ?? 0) >= $threshold;
    }

    /** @param array<string, mixed> $context */
    private function interpolate(string $message, array $context): string
    {
        $replacements = [];

        foreach ($context as $key => $value) {
            if (is_scalar($value) || $value === null || $value instanceof Stringable) {
                $replacements['{' . $key . '}'] = (string) $value;
            }
        }

        return strtr($message, $replacements);
    }

    /** @param array<string, mixed> $context */
    private function encodeContext(array $context): string
    {
        $encoded = json_encode($context, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR);

        return $encoded === false ? '{}' : $encoded;
    }

    private function write(string $line): void
    {
        $directory = dirname($this->path);

        if (!is_dir($directory) && !@mkdir($directory, 0775, true) && !is_dir($directory)) {
            return; // Logging must never take the request down.
        }

        @file_put_contents($this->path, $line, FILE_APPEND | LOCK_EX);
    }
}
