<?php

declare(strict_types=1);

namespace App\Security;

use App\Dto\Response\TokenView;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Security\Core\Exception\BadCredentialsException;

/**
 * Stateless HS256 access tokens. The merchant is re-checked against the (cached) identity on every
 * request, so deactivating a merchant takes effect within the identity cache TTL even while
 * already issued tokens are still formally valid.
 */
class JwtTokenService
{
    private const ALGORITHM = 'HS256';
    private const ISSUER = 'seller-api';
    private const LEEWAY_SECONDS = 5;

    public function __construct(
        #[Autowire(env: 'JWT_SECRET')]
        private readonly string $secret,
        #[Autowire(env: 'int:JWT_TTL')]
        private readonly int $ttl,
    ) {
        if (\strlen($secret) < 32) {
            throw new \InvalidArgumentException('JWT_SECRET must be at least 32 bytes long.');
        }
    }

    public function issue(string $merchantId): TokenView
    {
        $now = time();

        $token = JWT::encode([
            'iss' => self::ISSUER,
            'aud' => self::ISSUER,
            'sub' => $merchantId,
            'iat' => $now,
            'nbf' => $now,
            'exp' => $now + $this->ttl,
            'jti' => bin2hex(random_bytes(8)),
        ], $this->secret, self::ALGORITHM);

        return new TokenView($token, 'Bearer', $this->ttl);
    }

    /**
     * @return string the merchant id (subject)
     *
     * @throws BadCredentialsException when the token is malformed, expired, tampered with or foreign
     */
    public function parse(string $token): string
    {
        JWT::$leeway = self::LEEWAY_SECONDS;

        try {
            $claims = JWT::decode($token, new Key($this->secret, self::ALGORITHM));
        } catch (\Throwable) {
            throw new BadCredentialsException('Invalid or expired access token.');
        }

        if (($claims->iss ?? null) !== self::ISSUER || ($claims->aud ?? null) !== self::ISSUER) {
            throw new BadCredentialsException('Invalid access token.');
        }

        $subject = $claims->sub ?? null;
        if (!\is_string($subject) || '' === $subject) {
            throw new BadCredentialsException('Invalid access token.');
        }

        return $subject;
    }
}
