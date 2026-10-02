<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Tests\Support\ApiTestCase;
use Doctrine\ORM\EntityManagerInterface;

/**
 * The test environment uses tiny limits (see .env.test): 20 requests/minute per merchant,
 * 50 requests/minute per IP. Every test runs from its own random IP with its own merchant,
 * so buckets in Redis never collide.
 */
final class RateLimiterTest extends ApiTestCase
{
    public function testMerchantQuotaIsEnforcedWithInformativeHeaders(): void
    {
        $merchant = $this->createMerchant();

        for ($i = 1; $i <= 20; ++$i) {
            $this->requestJson('GET', '/api/v1/warehouses', $merchant->apiKey);

            self::assertResponseStatusCodeSame(200, \sprintf('Request %d is within the quota.', $i));
            self::assertSame('20', $this->responseHeader('X-RateLimit-Limit'));
            self::assertSame((string) (20 - $i), $this->responseHeader('X-RateLimit-Remaining'));
        }

        $body = $this->requestJson('GET', '/api/v1/warehouses', $merchant->apiKey);

        self::assertResponseStatusCodeSame(429);
        self::assertSame('application/problem+json', $this->responseHeader('Content-Type'));
        self::assertSame('rate_limit_exceeded', $body['code']);
        self::assertSame('0', $this->responseHeader('X-RateLimit-Remaining'));
        self::assertGreaterThanOrEqual(1, (int) $this->responseHeader('Retry-After'));
        self::assertLessThanOrEqual(60, (int) $this->responseHeader('Retry-After'));
        self::assertNotNull($this->responseHeader('X-RateLimit-Reset'));
    }

    public function testQuotaIsPerMerchantNotPerIp(): void
    {
        $exhausted = $this->createMerchant('Noisy neighbour');
        $innocent = $this->createMerchant('Quiet merchant');

        for ($i = 0; $i < 21; ++$i) {
            $this->requestJson('GET', '/api/v1/warehouses', $exhausted->apiKey);
        }
        self::assertResponseStatusCodeSame(429);

        // Same client IP, different merchant: unaffected.
        $this->requestJson('GET', '/api/v1/warehouses', $innocent->apiKey);
        self::assertResponseStatusCodeSame(200);
    }

    public function testRejectedRequestsDoNotReachTheApplication(): void
    {
        $merchant = $this->createMerchant();

        for ($i = 0; $i < 20; ++$i) {
            $this->requestJson('GET', '/api/v1/warehouses', $merchant->apiKey);
        }

        $this->requestJson('POST', '/api/v1/warehouses', $merchant->apiKey, ['code' => 'NOPE-01', 'name' => 'Must not be created']);
        self::assertResponseStatusCodeSame(429);

        // The merchant is still throttled, so check the database directly.
        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $count = $em->getConnection()->fetchOne('SELECT COUNT(*) FROM warehouses WHERE merchant_id = ?', [$merchant->id]);

        self::assertSame(0, (int) $count);
    }

    public function testIpLimitShedsAnonymousTrafficBeforeAuthentication(): void
    {
        for ($i = 1; $i <= 50; ++$i) {
            $this->requestJson('GET', '/api/v1/products');
            self::assertResponseStatusCodeSame(401, \sprintf('Request %d is still answered by the authentication layer.', $i));
        }

        $body = $this->requestJson('GET', '/api/v1/products');

        self::assertResponseStatusCodeSame(429);
        self::assertSame('ip_rate_limit_exceeded', $body['code']);
        self::assertNotNull($this->responseHeader('Retry-After'));
    }

    public function testDifferentIpsHaveIndependentBudgets(): void
    {
        for ($i = 0; $i < 51; ++$i) {
            $this->requestJson('GET', '/api/v1/products');
        }
        self::assertResponseStatusCodeSame(429);

        $this->switchToNewIp();
        $this->requestJson('GET', '/api/v1/products');

        self::assertResponseStatusCodeSame(401, 'Another IP gets a normal answer from the authentication layer.');
    }

    public function testHealthChecksAreExemptFromRateLimiting(): void
    {
        for ($i = 0; $i < 60; ++$i) {
            $this->client->request('GET', '/api/v1/health');
            self::assertResponseStatusCodeSame(200);
        }

        self::assertNull($this->responseHeader('X-RateLimit-Limit'));
    }
}
