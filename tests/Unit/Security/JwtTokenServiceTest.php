<?php

declare(strict_types=1);

namespace App\Tests\Unit\Security;

use App\Security\JwtTokenService;
use Firebase\JWT\JWT;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Security\Core\Exception\BadCredentialsException;

final class JwtTokenServiceTest extends TestCase
{
    private const SECRET = 'unit-test-secret-0123456789abcdef0123456789-padding-for-hs512-0123456789';

    public function testIssuedTokenRoundTrips(): void
    {
        $service = new JwtTokenService(self::SECRET, 900);

        $token = $service->issue('merchant-42');

        self::assertSame('Bearer', $token->tokenType);
        self::assertSame(900, $token->expiresIn);
        self::assertSame('merchant-42', $service->parse($token->accessToken));
    }

    public function testTokenSignedWithAnotherSecretIsRejected(): void
    {
        $forged = (new JwtTokenService('another-secret-0123456789abcdef0123456789', 900))->issue('merchant-42');

        $this->expectException(BadCredentialsException::class);

        (new JwtTokenService(self::SECRET, 900))->parse($forged->accessToken);
    }

    public function testExpiredTokenIsRejected(): void
    {
        $expired = JWT::encode(
            ['iss' => 'seller-api', 'aud' => 'seller-api', 'sub' => 'merchant-42', 'iat' => time() - 100, 'exp' => time() - 50],
            self::SECRET,
            'HS256',
        );

        $this->expectException(BadCredentialsException::class);

        (new JwtTokenService(self::SECRET, 900))->parse($expired);
    }

    public function testTokenFromAnotherIssuerIsRejected(): void
    {
        $foreign = JWT::encode(
            ['iss' => 'somebody-else', 'aud' => 'seller-api', 'sub' => 'merchant-42', 'exp' => time() + 100],
            self::SECRET,
            'HS256',
        );

        $this->expectException(BadCredentialsException::class);

        (new JwtTokenService(self::SECRET, 900))->parse($foreign);
    }

    public function testTokenWithAnotherAlgorithmIsRejected(): void
    {
        $downgraded = JWT::encode(
            ['iss' => 'seller-api', 'aud' => 'seller-api', 'sub' => 'merchant-42', 'exp' => time() + 100],
            self::SECRET,
            'HS512',
        );

        $this->expectException(BadCredentialsException::class);

        (new JwtTokenService(self::SECRET, 900))->parse($downgraded);
    }

    public function testGarbageIsRejected(): void
    {
        $this->expectException(BadCredentialsException::class);

        (new JwtTokenService(self::SECRET, 900))->parse('not.a.jwt');
    }

    public function testShortSecretsAreRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new JwtTokenService('too-short', 900);
    }
}
