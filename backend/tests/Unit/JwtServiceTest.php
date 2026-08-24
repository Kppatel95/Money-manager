<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Auth\JwtService;
use PHPUnit\Framework\TestCase;

final class JwtServiceTest extends TestCase
{
    public function testIssuedTokenVerifiesAndCarriesThePayload(): void
    {
        $jwt = new JwtService('unit-test-secret');

        $token = $jwt->issue(42, 'jane@example.com');
        $payload = $jwt->verify($token);

        self::assertNotNull($payload);
        self::assertSame(42, $payload['sub']);
        self::assertSame('jane@example.com', $payload['email']);
    }

    public function testTokenSignedWithADifferentSecretIsRejected(): void
    {
        $issuer = new JwtService('secret-a');
        $verifier = new JwtService('secret-b');

        $token = $issuer->issue(1, 'jane@example.com');

        self::assertNull($verifier->verify($token));
    }

    public function testMalformedTokenIsRejected(): void
    {
        $jwt = new JwtService('unit-test-secret');

        self::assertNull($jwt->verify('not-a-real-token'));
    }

    public function testTamperedTokenIsRejected(): void
    {
        $jwt = new JwtService('unit-test-secret');
        $token = $jwt->issue(1, 'jane@example.com');

        // Flip a character in the middle of the signature segment. (The
        // very last base64url character only encodes spare padding bits,
        // so mutating it can decode to the same bytes -- this picks a
        // position that is guaranteed to change the signature value.)
        [$header, $payload, $signature] = explode('.', $token);
        $midpoint = intdiv(strlen($signature), 2);
        $flipped = $signature[$midpoint] === 'A' ? 'B' : 'A';
        $signature = substr_replace($signature, $flipped, $midpoint, 1);
        $tampered = "{$header}.{$payload}.{$signature}";

        self::assertNull($jwt->verify($tampered));
    }
}
