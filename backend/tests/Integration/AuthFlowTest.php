<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Services\AuthService;
use Tests\Support\ApiTestCase;

/**
 * The full auth surface driven through the real router.
 */
final class AuthFlowTest extends ApiTestCase
{
    public function testRegisterLoginRefreshLogout(): void
    {
        $register = $this->post('/api/v1/auth/register', [
            'name' => 'Ada',
            'email' => 'ada@example.test',
            'password' => 'correct horse battery',
        ]);

        $this->assertStatus(201, $register);
        $registered = $register->decoded()['data'];
        $this->assertSame('ada@example.test', $registered['user']['email']);
        $this->assertSame('Bearer', $registered['token_type']);
        $this->assertSame(900, $registered['expires_in']);
        $this->assertNotEmpty($registered['access_token']);
        $this->assertNotEmpty($registered['refresh_token']);
        $this->assertArrayNotHasKey('password_hash', $registered['user']);

        // The access token works immediately.
        $this->assertStatus(200, $this->get('/api/v1/auth/me', $registered['access_token']));

        $login = $this->post('/api/v1/auth/login', [
            'email' => 'ada@example.test',
            'password' => 'correct horse battery',
        ]);
        $this->assertStatus(200, $login);
        $session = $login->decoded()['data'];

        $refresh = $this->post('/api/v1/auth/refresh', ['refresh_token' => $session['refresh_token']]);
        $this->assertStatus(200, $refresh);
        $rotated = $refresh->decoded()['data'];
        $this->assertNotSame($session['refresh_token'], $rotated['refresh_token'], 'refresh tokens must rotate');
        $this->assertStatus(200, $this->get('/api/v1/auth/me', $rotated['access_token']));

        // The consumed refresh token is dead.
        $reuse = $this->post('/api/v1/auth/refresh', ['refresh_token' => $session['refresh_token']]);
        $this->assertStatus(401, $reuse);
        $this->assertErrorCode('UNAUTHORIZED', $reuse);

        $logout = $this->post('/api/v1/auth/logout', ['refresh_token' => $rotated['refresh_token']], $rotated['access_token']);
        $this->assertStatus(204, $logout);

        $afterLogout = $this->post('/api/v1/auth/refresh', ['refresh_token' => $rotated['refresh_token']]);
        $this->assertStatus(401, $afterLogout);
    }

    public function testRefreshTokensAreStoredOnlyAsHashes(): void
    {
        $user = $this->registerUser();

        $stored = $this->pdo->query('SELECT token_hash FROM refresh_tokens')->fetchAll();

        $this->assertCount(1, $stored);
        $this->assertNotSame($user['refresh_token'], $stored[0]['token_hash']);
        $this->assertSame(hash('sha256', $user['refresh_token']), $stored[0]['token_hash']);
    }

    public function testRegistrationValidatesItsInput(): void
    {
        $response = $this->post('/api/v1/auth/register', [
            'name' => '',
            'email' => 'not-an-email',
            'password' => 'short',
        ]);

        $this->assertStatus(422, $response);
        $this->assertErrorCode('VALIDATION_ERROR', $response);
        $this->assertSame(
            ['name', 'email', 'password'],
            array_keys($response->decoded()['error']['details'])
        );
    }

    public function testDuplicateEmailIsAConflict(): void
    {
        $this->registerUser('taken@example.test');

        $response = $this->post('/api/v1/auth/register', [
            'name' => 'Impostor',
            'email' => 'taken@example.test',
            'password' => 'password123',
        ]);

        $this->assertStatus(409, $response);
        $this->assertErrorCode('CONFLICT', $response);
    }

    public function testWrongPasswordIs401(): void
    {
        $this->registerUser('ada@example.test');

        $response = $this->post('/api/v1/auth/login', [
            'email' => 'ada@example.test',
            'password' => 'wrong',
        ]);

        $this->assertStatus(401, $response);
        $this->assertErrorCode('UNAUTHORIZED', $response);
    }

    public function testUnknownEmailIs401AndDoesNotRevealAnything(): void
    {
        $response = $this->post('/api/v1/auth/login', [
            'email' => 'nobody@example.test',
            'password' => 'password123',
        ]);

        $this->assertStatus(401, $response);
        $this->assertSame('Invalid email or password.', $response->decoded()['error']['message']);
    }

    public function testRepeatedFailuresLockTheAccountWith429(): void
    {
        $this->registerUser('ada@example.test');

        for ($attempt = 1; $attempt <= AuthService::MAX_FAILED_ATTEMPTS; $attempt++) {
            $this->assertStatus(401, $this->post('/api/v1/auth/login', [
                'email' => 'ada@example.test',
                'password' => 'wrong',
            ]), "attempt {$attempt}");
        }

        $locked = $this->post('/api/v1/auth/login', ['email' => 'ada@example.test', 'password' => 'wrong']);
        $this->assertStatus(429, $locked);
        $this->assertErrorCode('RATE_LIMITED', $locked);
        $this->assertArrayHasKey('Retry-After', $locked->headers);

        // Even the correct password is refused while the lockout stands.
        $correct = $this->post('/api/v1/auth/login', ['email' => 'ada@example.test', 'password' => 'password123']);
        $this->assertStatus(429, $correct);
    }

    public function testTheLockoutIsPerEmail(): void
    {
        $this->registerUser('ada@example.test');
        $this->registerUser('grace@example.test');

        for ($attempt = 1; $attempt <= AuthService::MAX_FAILED_ATTEMPTS; $attempt++) {
            $this->post('/api/v1/auth/login', ['email' => 'ada@example.test', 'password' => 'wrong']);
        }

        $this->assertStatus(429, $this->post('/api/v1/auth/login', ['email' => 'ada@example.test', 'password' => 'wrong']));
        $this->assertStatus(200, $this->post('/api/v1/auth/login', [
            'email' => 'grace@example.test',
            'password' => 'password123',
        ]));
    }

    public function testASuccessfulLoginClearsTheFailureCount(): void
    {
        $this->registerUser('ada@example.test');

        for ($attempt = 1; $attempt < AuthService::MAX_FAILED_ATTEMPTS; $attempt++) {
            $this->post('/api/v1/auth/login', ['email' => 'ada@example.test', 'password' => 'wrong']);
        }

        $this->assertStatus(200, $this->post('/api/v1/auth/login', [
            'email' => 'ada@example.test',
            'password' => 'password123',
        ]));
        $this->assertSame(0, (int) $this->pdo->query('SELECT COUNT(*) FROM login_attempts')->fetchColumn());

        // The counter really restarted, so four more failures are still fine.
        for ($attempt = 1; $attempt < AuthService::MAX_FAILED_ATTEMPTS; $attempt++) {
            $this->assertStatus(401, $this->post('/api/v1/auth/login', [
                'email' => 'ada@example.test',
                'password' => 'wrong',
            ]));
        }
    }

    public function testProtectedEndpointsRejectMissingOrBrokenTokens(): void
    {
        $noToken = $this->get('/api/v1/accounts');
        $this->assertStatus(401, $noToken);
        $this->assertErrorCode('UNAUTHORIZED', $noToken);

        $this->assertStatus(401, $this->get('/api/v1/accounts', 'not-a-jwt'));
        $this->assertStatus(401, $this->get('/api/v1/accounts', 'eyJhbGciOiJIUzI1NiJ9.eyJzdWIiOjF9.bogus'));
    }

    public function testExpiredAccessTokensAreRejected(): void
    {
        $user = $this->registerUser();
        $expired = (new \App\Auth\JwtService(null, -10))->issue($user['id'], $user['email']);

        $this->assertStatus(401, $this->get('/api/v1/accounts', $expired));
    }

    public function testRefreshRequiresAToken(): void
    {
        $this->assertStatus(401, $this->post('/api/v1/auth/refresh', []));
        $this->assertStatus(401, $this->post('/api/v1/auth/refresh', ['refresh_token' => 'made-up']));
    }

    public function testLogoutRequiresAnAccessToken(): void
    {
        $user = $this->registerUser();

        $this->assertStatus(401, $this->post('/api/v1/auth/logout', ['refresh_token' => $user['refresh_token']]));
    }

    public function testAnotherUsersRefreshTokenCannotBeRevoked(): void
    {
        $ada = $this->registerUser('ada@example.test');
        $grace = $this->registerUser('grace@example.test');

        $this->assertStatus(204, $this->post('/api/v1/auth/logout', [
            'refresh_token' => $grace['refresh_token'],
        ], $ada['token']));

        // Grace's token still works: Ada could not revoke it.
        $this->assertStatus(200, $this->post('/api/v1/auth/refresh', ['refresh_token' => $grace['refresh_token']]));
    }

    public function testForgotPasswordAlwaysRespondsTheSameWayButOnlyIssuesATokenForARealAccount(): void
    {
        $this->registerUser('ada@example.test');

        $real = $this->post('/api/v1/auth/forgot-password', ['email' => 'ada@example.test']);
        $unknown = $this->post('/api/v1/auth/forgot-password', ['email' => 'nobody@example.test']);

        // Same status, same (empty) body either way -- the response cannot be
        // used to tell whether an email is registered.
        $this->assertStatus(204, $real);
        $this->assertStatus(204, $unknown);
        $this->assertSame($real->body, $unknown->body);

        // But server-side, only the real account actually got a token.
        $this->assertSame(1, (int) $this->pdo->query('SELECT COUNT(*) FROM password_reset_tokens')->fetchColumn());
    }

    public function testForgotPasswordValidatesTheEmail(): void
    {
        $response = $this->post('/api/v1/auth/forgot-password', ['email' => 'not-an-email']);

        $this->assertStatus(422, $response);
        $this->assertErrorCode('VALIDATION_ERROR', $response);
    }

    public function testRequestingASecondResetInvalidatesTheFirstLink(): void
    {
        $this->registerUser('ada@example.test');

        $this->post('/api/v1/auth/forgot-password', ['email' => 'ada@example.test']);
        $firstHash = $this->pdo->query('SELECT token_hash FROM password_reset_tokens')->fetchColumn();

        $this->post('/api/v1/auth/forgot-password', ['email' => 'ada@example.test']);
        $rows = $this->pdo->query('SELECT token_hash FROM password_reset_tokens')->fetchAll();

        $this->assertCount(1, $rows, 'only the newest link should remain valid');
        $this->assertNotSame($firstHash, $rows[0]['token_hash']);
    }

    public function testAValidTokenResetsThePasswordAndCanOnlyBeUsedOnce(): void
    {
        $user = $this->registerUser('ada@example.test');
        $token = $this->plantResetToken((int) $user['id']);

        $reset = $this->post('/api/v1/auth/reset-password', ['token' => $token, 'password' => 'brand-new-password']);
        $this->assertStatus(204, $reset);

        // The old password no longer works; the new one does.
        $this->assertStatus(401, $this->post('/api/v1/auth/login', [
            'email' => 'ada@example.test', 'password' => 'password123',
        ]));
        $this->assertStatus(200, $this->post('/api/v1/auth/login', [
            'email' => 'ada@example.test', 'password' => 'brand-new-password',
        ]));

        // The token is spent.
        $reuse = $this->post('/api/v1/auth/reset-password', ['token' => $token, 'password' => 'another-password']);
        $this->assertStatus(401, $reuse);
        $this->assertErrorCode('UNAUTHORIZED', $reuse);
    }

    public function testAnExpiredTokenIsRejected(): void
    {
        $user = $this->registerUser('ada@example.test');
        $token = $this->plantResetToken((int) $user['id'], expiresInSeconds: -60);

        $response = $this->post('/api/v1/auth/reset-password', ['token' => $token, 'password' => 'brand-new-password']);

        $this->assertStatus(401, $response);
        $this->assertErrorCode('UNAUTHORIZED', $response);
    }

    public function testAnUnknownTokenIsRejected(): void
    {
        $response = $this->post('/api/v1/auth/reset-password', [
            'token' => 'this-was-never-issued', 'password' => 'brand-new-password',
        ]);

        $this->assertStatus(401, $response);
    }

    public function testResetPasswordValidatesTheNewPassword(): void
    {
        $user = $this->registerUser('ada@example.test');
        $token = $this->plantResetToken((int) $user['id']);

        $response = $this->post('/api/v1/auth/reset-password', ['token' => $token, 'password' => 'short']);

        $this->assertStatus(422, $response);
        $this->assertErrorCode('VALIDATION_ERROR', $response);
    }

    public function testResettingThePasswordSignsOutEveryExistingSession(): void
    {
        $user = $this->registerUser('ada@example.test');
        $login = $this->post('/api/v1/auth/login', ['email' => 'ada@example.test', 'password' => 'password123']);
        $oldRefreshToken = $login->decoded()['data']['refresh_token'];

        $token = $this->plantResetToken((int) $user['id']);
        $this->post('/api/v1/auth/reset-password', ['token' => $token, 'password' => 'brand-new-password']);

        $this->assertStatus(401, $this->post('/api/v1/auth/refresh', ['refresh_token' => $oldRefreshToken]));
    }

    public function testResettingThePasswordClearsAnExistingLockout(): void
    {
        $user = $this->registerUser('ada@example.test');

        for ($attempt = 1; $attempt <= AuthService::MAX_FAILED_ATTEMPTS; $attempt++) {
            $this->post('/api/v1/auth/login', ['email' => 'ada@example.test', 'password' => 'wrong']);
        }
        $this->assertStatus(429, $this->post('/api/v1/auth/login', [
            'email' => 'ada@example.test', 'password' => 'wrong',
        ]));

        $token = $this->plantResetToken((int) $user['id']);
        $this->post('/api/v1/auth/reset-password', ['token' => $token, 'password' => 'brand-new-password']);

        // The lockout is gone, not just outlasted -- this runs immediately after.
        $this->assertStatus(200, $this->post('/api/v1/auth/login', [
            'email' => 'ada@example.test', 'password' => 'brand-new-password',
        ]));
    }

    /**
     * Plants a reset token directly in the database, the way the real flow
     * would after emailing it out -- there is no way to recover a token's
     * plaintext from the API once /auth/forgot-password has hashed it away,
     * so the consume-side tests need to seed one themselves.
     */
    private function plantResetToken(int $userId, int $expiresInSeconds = 3600): string
    {
        $token = bin2hex(random_bytes(32));

        $stmt = $this->pdo->prepare(
            'INSERT INTO password_reset_tokens (user_id, token_hash, expires_at) VALUES (:user_id, :hash, :expires_at)'
        );
        $stmt->execute([
            'user_id' => $userId,
            'hash' => hash('sha256', $token),
            'expires_at' => gmdate('Y-m-d H:i:s', time() + $expiresInSeconds),
        ]);

        return $token;
    }
}
