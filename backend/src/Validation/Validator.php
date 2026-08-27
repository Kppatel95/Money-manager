<?php

declare(strict_types=1);

namespace App\Validation;

use App\Exceptions\ValidationException;
use App\Support\Money;

/**
 * Collects field errors while reading a payload, then throws them all at once
 * so the client gets every problem in a single 422 instead of playing
 * whack-a-mole one field per round trip.
 *
 * The optional*() readers return null when a key is absent, which is what
 * makes partial PUT updates straightforward: ask for what was sent, ignore
 * the rest.
 */
final class Validator
{
    /** @var array<string, string> */
    private array $errors = [];

    /** @param array<string, mixed> $data */
    public function __construct(private readonly array $data)
    {
    }

    public function has(string $key): bool
    {
        return array_key_exists($key, $this->data);
    }

    public function add(string $key, string $message): void
    {
        $this->errors[$key] ??= $message;
    }

    /** @return array<string, string> */
    public function errors(): array
    {
        return $this->errors;
    }

    /** @throws ValidationException */
    public function validate(): void
    {
        if ($this->errors !== []) {
            throw new ValidationException($this->errors);
        }
    }

    public function requiredString(string $key, int $maxLength = 255, ?string $label = null): string
    {
        $value = $this->data[$key] ?? null;

        if (!is_string($value) || trim($value) === '') {
            $this->add($key, ($label ?? self::label($key)) . ' is required.');
            return '';
        }

        $value = trim($value);

        if (mb_strlen($value) > $maxLength) {
            $this->add($key, ($label ?? self::label($key)) . " must be at most {$maxLength} characters.");
        }

        return $value;
    }

    public function optionalString(string $key, int $maxLength = 255, bool $nullable = true): ?string
    {
        if (!$this->has($key)) {
            return null;
        }

        $value = $this->data[$key];

        if ($value === null) {
            if (!$nullable) {
                $this->add($key, self::label($key) . ' may not be null.');
            }
            return null;
        }

        if (!is_string($value)) {
            $this->add($key, self::label($key) . ' must be a string.');
            return null;
        }

        $value = trim($value);

        if (mb_strlen($value) > $maxLength) {
            $this->add($key, self::label($key) . " must be at most {$maxLength} characters.");
        }

        return $value;
    }

    /** @param array<int, string> $allowed */
    public function requiredEnum(string $key, array $allowed): string
    {
        $value = $this->data[$key] ?? null;

        if (!is_string($value) || !in_array($value, $allowed, true)) {
            $this->add($key, self::label($key) . ' must be one of: ' . implode(', ', $allowed) . '.');
            return '';
        }

        return $value;
    }

    /** @param array<int, string> $allowed */
    public function optionalEnum(string $key, array $allowed): ?string
    {
        if (!$this->has($key) || $this->data[$key] === null) {
            return null;
        }

        return $this->requiredEnum($key, $allowed);
    }

    /** Amount in major units, returned as positive cents. */
    public function requiredAmountCents(string $key = 'amount'): int
    {
        $value = $this->data[$key] ?? null;
        $cents = Money::toCents($value);

        if ($cents === null) {
            $this->add($key, self::label($key) . ' must be a number.');
            return 0;
        }

        if ($cents <= 0) {
            $this->add($key, self::label($key) . ' must be greater than zero.');
            return 0;
        }

        return $cents;
    }

    public function optionalAmountCents(string $key = 'amount', bool $allowNegative = false): ?int
    {
        if (!$this->has($key) || $this->data[$key] === null) {
            return null;
        }

        $cents = Money::toCents($this->data[$key]);

        if ($cents === null) {
            $this->add($key, self::label($key) . ' must be a number.');
            return null;
        }

        if (!$allowNegative && $cents <= 0) {
            $this->add($key, self::label($key) . ' must be greater than zero.');
            return null;
        }

        return $cents;
    }

    /** Signed amount in cents -- account opening balances may legitimately be negative. */
    public function signedAmountCents(string $key, int $default = 0): int
    {
        if (!$this->has($key) || $this->data[$key] === null) {
            return $default;
        }

        $cents = Money::toCents($this->data[$key]);

        if ($cents === null) {
            $this->add($key, self::label($key) . ' must be a number.');
            return $default;
        }

        return $cents;
    }

    public function requiredId(string $key): int
    {
        $value = $this->data[$key] ?? null;

        if (!is_int($value) && !(is_string($value) && ctype_digit($value))) {
            $this->add($key, self::label($key) . ' is required.');
            return 0;
        }

        $id = (int) $value;

        if ($id <= 0) {
            $this->add($key, self::label($key) . ' is not valid.');
            return 0;
        }

        return $id;
    }

    public function optionalId(string $key): ?int
    {
        if (!$this->has($key) || $this->data[$key] === null || $this->data[$key] === '') {
            return null;
        }

        return $this->requiredId($key);
    }

    public function requiredDate(string $key): string
    {
        $value = $this->data[$key] ?? null;

        if (!is_string($value) || !self::isDate($value)) {
            $this->add($key, self::label($key) . ' must be a date in YYYY-MM-DD format.');
            return '';
        }

        return $value;
    }

    public function optionalDate(string $key): ?string
    {
        if (!$this->has($key) || $this->data[$key] === null) {
            return null;
        }

        return $this->requiredDate($key);
    }

    public function requiredMonth(string $key = 'month'): string
    {
        $value = $this->data[$key] ?? null;

        if (!is_string($value) || preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $value) !== 1) {
            $this->add($key, self::label($key) . ' must be in YYYY-MM format.');
            return '';
        }

        return $value;
    }

    public function optionalBool(string $key): ?bool
    {
        if (!$this->has($key) || $this->data[$key] === null) {
            return null;
        }

        $value = $this->data[$key];

        if (is_bool($value)) {
            return $value;
        }

        if (in_array($value, [0, 1, '0', '1', 'true', 'false'], true)) {
            return in_array($value, [1, '1', 'true'], true);
        }

        $this->add($key, self::label($key) . ' must be true or false.');

        return null;
    }

    public function optionalColor(string $key = 'color'): ?string
    {
        $value = $this->optionalString($key, 7);

        if ($value === null || $value === '') {
            return null;
        }

        if (preg_match('/^#[0-9a-fA-F]{6}$/', $value) !== 1) {
            $this->add($key, 'Color must be a hex value such as #3366ff.');
            return null;
        }

        return strtolower($value);
    }

    public function requiredEmail(string $key = 'email'): string
    {
        $value = $this->data[$key] ?? null;
        $email = is_string($value) ? trim($value) : '';

        if ($email === '') {
            $this->add($key, 'Email is required.');
            return '';
        }

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $this->add($key, 'Email is not valid.');
            return '';
        }

        return strtolower($email);
    }

    public function requiredPassword(string $key = 'password', int $minLength = 8): string
    {
        $value = $this->data[$key] ?? null;

        if (!is_string($value) || $value === '') {
            $this->add($key, 'Password is required.');
            return '';
        }

        if (mb_strlen($value) < $minLength) {
            $this->add($key, "Password must be at least {$minLength} characters.");
            return '';
        }

        return $value;
    }

    /**
     * Tags arrive either as a JSON array of strings or as a comma-separated
     * string; both normalise to a list of trimmed, non-empty strings.
     *
     * @return array<int, string>|null
     */
    public function optionalTags(string $key = 'tags'): ?array
    {
        if (!$this->has($key) || $this->data[$key] === null) {
            return null;
        }

        $value = $this->data[$key];

        if (is_string($value)) {
            $value = $value === '' ? [] : explode(',', $value);
        }

        if (!is_array($value)) {
            $this->add($key, 'Tags must be an array of strings.');
            return null;
        }

        $tags = [];

        foreach ($value as $tag) {
            if (!is_string($tag) && !is_numeric($tag)) {
                $this->add($key, 'Tags must be an array of strings.');
                return null;
            }

            $tag = trim((string) $tag);

            if ($tag !== '' && !in_array($tag, $tags, true)) {
                $tags[] = mb_substr($tag, 0, 40);
            }
        }

        return $tags;
    }

    public static function isDate(string $value): bool
    {
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) !== 1) {
            return false;
        }

        [$y, $m, $d] = array_map('intval', explode('-', $value));

        return checkdate($m, $d, $y);
    }

    private static function label(string $key): string
    {
        return ucfirst(str_replace('_', ' ', $key));
    }
}
