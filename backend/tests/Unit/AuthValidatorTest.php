<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Validation\AuthValidator;
use App\Exceptions\ValidationException;
use PHPUnit\Framework\TestCase;

final class AuthValidatorTest extends TestCase
{
    public function testValidRegistrationIsNormalized(): void
    {
        $result = AuthValidator::validateRegistration([
            'name' => '  Jane Doe  ',
            'email' => '  JANE@Example.com ',
            'password' => 'correct-horse',
        ]);

        self::assertSame('Jane Doe', $result['name']);
        self::assertSame('jane@example.com', $result['email']);
        self::assertSame('correct-horse', $result['password']);
    }

    public function testRegistrationRequiresName(): void
    {
        try {
            AuthValidator::validateRegistration([
                'email' => 'jane@example.com',
                'password' => 'correct-horse',
            ]);
            self::fail('Expected a ValidationException.');
        } catch (ValidationException $e) {
            self::assertArrayHasKey('name', $e->errors());
        }
    }

    public function testRegistrationRejectsInvalidEmail(): void
    {
        try {
            AuthValidator::validateRegistration([
                'name' => 'Jane',
                'email' => 'not-an-email',
                'password' => 'correct-horse',
            ]);
            self::fail('Expected a ValidationException.');
        } catch (ValidationException $e) {
            self::assertArrayHasKey('email', $e->errors());
        }
    }

    public function testRegistrationRejectsShortPassword(): void
    {
        try {
            AuthValidator::validateRegistration([
                'name' => 'Jane',
                'email' => 'jane@example.com',
                'password' => 'short',
            ]);
            self::fail('Expected a ValidationException.');
        } catch (ValidationException $e) {
            self::assertArrayHasKey('password', $e->errors());
        }
    }

    public function testValidLoginPasses(): void
    {
        $result = AuthValidator::validateLogin([
            'email' => 'jane@example.com',
            'password' => 'anything',
        ]);

        self::assertSame('jane@example.com', $result['email']);
        self::assertSame('anything', $result['password']);
    }

    public function testLoginRequiresEmailAndPassword(): void
    {
        try {
            AuthValidator::validateLogin([]);
            self::fail('Expected a ValidationException.');
        } catch (ValidationException $e) {
            $errors = $e->errors();
            self::assertArrayHasKey('email', $errors);
            self::assertArrayHasKey('password', $errors);
        }
    }
}
