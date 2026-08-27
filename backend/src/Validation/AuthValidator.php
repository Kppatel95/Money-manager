<?php

declare(strict_types=1);

namespace App\Validation;

use App\Exceptions\ValidationException;

/**
 * Validates registration and login payloads.
 */
final class AuthValidator
{
    private const MIN_PASSWORD_LENGTH = 8;

    /**
     * @param array<string, mixed> $data
     * @return array{name: string, email: string, password: string}
     * @throws ValidationException
     */
    public static function validateRegistration(array $data): array
    {
        $errors = [];

        $name = is_string($data['name'] ?? null) ? trim($data['name']) : '';
        if ($name === '') {
            $errors['name'] = 'Name is required.';
        }

        $email = self::validateEmail($data['email'] ?? null, $errors);
        $password = self::validatePassword($data['password'] ?? null, $errors);

        if ($errors !== []) {
            throw new ValidationException($errors);
        }

        return ['name' => $name, 'email' => $email, 'password' => $password];
    }

    /**
     * @param array<string, mixed> $data
     * @return array{email: string, password: string}
     * @throws ValidationException
     */
    public static function validateLogin(array $data): array
    {
        $errors = [];

        $email = is_string($data['email'] ?? null) ? trim($data['email']) : '';
        if ($email === '') {
            $errors['email'] = 'Email is required.';
        }

        $password = is_string($data['password'] ?? null) ? $data['password'] : '';
        if ($password === '') {
            $errors['password'] = 'Password is required.';
        }

        if ($errors !== []) {
            throw new ValidationException($errors);
        }

        return ['email' => $email, 'password' => $password];
    }

    private static function validateEmail(mixed $value, array &$errors): string
    {
        $email = is_string($value) ? trim($value) : '';

        if ($email === '') {
            $errors['email'] = 'Email is required.';
            return '';
        }

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $errors['email'] = 'Email is not valid.';
            return '';
        }

        return strtolower($email);
    }

    private static function validatePassword(mixed $value, array &$errors): string
    {
        $password = is_string($value) ? $value : '';

        if (mb_strlen($password) < self::MIN_PASSWORD_LENGTH) {
            $errors['password'] = 'Password must be at least ' . self::MIN_PASSWORD_LENGTH . ' characters.';
            return '';
        }

        return $password;
    }
}
