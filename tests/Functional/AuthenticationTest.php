<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\Merchant;
use App\Tests\Support\ApiTestCase;
use Doctrine\ORM\EntityManagerInterface;
use Firebase\JWT\JWT;

final class AuthenticationTest extends ApiTestCase
{
    private const INVALID_KEY = 'sk_000000000000_000000000000000000000000000000000000000000000000';

    public function testRequestWithoutCredentialsIsRejected(): void
    {
        $body = $this->requestJson('GET', '/api/v1/products');

        self::assertResponseStatusCodeSame(401);
        self::assertSame('application/problem+json', $this->responseHeader('Content-Type'));
        self::assertSame('unauthorized', $body['code']);
        self::assertNotNull($this->responseHeader('WWW-Authenticate'));
    }

    public function testValidApiKeyIsAccepted(): void
    {
        $merchant = $this->createMerchant();

        $body = $this->requestJson('GET', '/api/v1/products', $merchant->apiKey);

        self::assertResponseStatusCodeSame(200);
        self::assertSame([], $body['data']);
        self::assertSame(0, $body['meta']['total']);
    }

    public function testUnknownAndMalformedKeysAreRejected(): void
    {
        $this->requestJson('GET', '/api/v1/products', self::INVALID_KEY);
        self::assertResponseStatusCodeSame(401);

        $this->requestJson('GET', '/api/v1/products', 'not-an-api-key');
        self::assertResponseStatusCodeSame(401);
    }

    public function testKeyWithValidPrefixButWrongSecretIsRejected(): void
    {
        $merchant = $this->createMerchant();
        $forged = substr($merchant->apiKey, 0, 16).str_repeat('0', 48);

        $this->requestJson('GET', '/api/v1/products', $forged);

        self::assertResponseStatusCodeSame(401);
    }

    public function testDeactivatedMerchantCannotAuthenticate(): void
    {
        $merchant = $this->createMerchant();

        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $em->getRepository(Merchant::class)->find($merchant->id)?->deactivate();
        $em->flush();

        $this->requestJson('GET', '/api/v1/products', $merchant->apiKey);

        self::assertResponseStatusCodeSame(401);
    }

    public function testApiKeyCanBeExchangedForABearerToken(): void
    {
        $merchant = $this->createMerchant();

        $token = $this->requestJson('POST', '/api/v1/auth/token', json: ['api_key' => $merchant->apiKey]);

        self::assertResponseStatusCodeSame(200);
        self::assertSame('Bearer', $token['token_type']);
        self::assertSame(900, $token['expires_in']);

        $this->requestJson('GET', '/api/v1/products', headers: ['Authorization' => 'Bearer '.$token['access_token']]);
        self::assertResponseStatusCodeSame(200);
    }

    public function testTamperedAndExpiredTokensAreRejected(): void
    {
        $merchant = $this->createMerchant();
        $token = $this->requestJson('POST', '/api/v1/auth/token', json: ['api_key' => $merchant->apiKey])['access_token'];

        $this->requestJson('GET', '/api/v1/products', headers: ['Authorization' => 'Bearer '.substr((string) $token, 0, -2).'xx']);
        self::assertResponseStatusCodeSame(401);

        $expired = JWT::encode([
            'iss' => 'seller-api',
            'aud' => 'seller-api',
            'sub' => $merchant->id,
            'iat' => time() - 7200,
            'exp' => time() - 3600,
        ], (string) $_SERVER['JWT_SECRET'], 'HS256');

        $this->requestJson('GET', '/api/v1/products', headers: ['Authorization' => 'Bearer '.$expired]);
        self::assertResponseStatusCodeSame(401);
    }

    public function testTokenEndpointRejectsInvalidKeysAndValidatesPayload(): void
    {
        $this->requestJson('POST', '/api/v1/auth/token', json: ['api_key' => self::INVALID_KEY]);
        self::assertResponseStatusCodeSame(401);

        $this->requestJson('POST', '/api/v1/auth/token', json: ['api_key' => '']);
        self::assertResponseStatusCodeSame(422);
    }

    public function testRepeatedFailedAttemptsBlockTheIpEvenForValidKeys(): void
    {
        $merchant = $this->createMerchant();

        // The budget in the test environment is 3 failures per IP.
        for ($attempt = 1; $attempt <= 3; ++$attempt) {
            $this->requestJson('GET', '/api/v1/products', self::INVALID_KEY);
            self::assertResponseStatusCodeSame(401, \sprintf('Attempt %d should be a plain 401.', $attempt));
        }

        $body = $this->requestJson('GET', '/api/v1/products', self::INVALID_KEY);
        self::assertResponseStatusCodeSame(429);
        self::assertSame('too_many_failed_attempts', $body['code']);
        self::assertGreaterThan(0, (int) $this->responseHeader('Retry-After'));

        // Blocked means blocked: a correct key from the same IP is refused as well ...
        $this->requestJson('GET', '/api/v1/products', $merchant->apiKey);
        self::assertResponseStatusCodeSame(429);

        // ... while other clients are not affected.
        $this->switchToNewIp();
        $this->requestJson('GET', '/api/v1/products', $merchant->apiKey);
        self::assertResponseStatusCodeSame(200);
    }

    public function testTokenEndpointIsProtectedAgainstBruteForce(): void
    {
        for ($attempt = 1; $attempt <= 3; ++$attempt) {
            $this->requestJson('POST', '/api/v1/auth/token', json: ['api_key' => self::INVALID_KEY]);
            self::assertResponseStatusCodeSame(401);
        }

        $body = $this->requestJson('POST', '/api/v1/auth/token', json: ['api_key' => self::INVALID_KEY]);

        self::assertResponseStatusCodeSame(429);
        self::assertSame('too_many_failed_attempts', $body['code']);
    }

    public function testSuccessfulAuthenticationDoesNotConsumeTheFailureBudget(): void
    {
        $merchant = $this->createMerchant();

        for ($i = 0; $i < 6; ++$i) {
            $this->requestJson('GET', '/api/v1/products', $merchant->apiKey);
            self::assertResponseStatusCodeSame(200);
        }
    }
}
