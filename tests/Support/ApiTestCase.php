<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Entity\Merchant;
use App\Security\ApiKeyService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Base class for functional tests against the real stack (PostgreSQL + Redis).
 *
 * Isolation strategy: no global cleanup. Every test creates its own merchant (unique id, unique API key)
 * and talks to the API from a random client IP, so database rows, cache keys, rate limiter buckets and
 * idempotency keys of different tests never collide - tests can run repeatedly against the same services.
 */
abstract class ApiTestCase extends WebTestCase
{
    protected KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = static::createClient(server: ['REMOTE_ADDR' => self::randomIp()]);
    }

    protected static function randomIp(): string
    {
        return \sprintf('10.%d.%d.%d', random_int(1, 254), random_int(0, 254), random_int(1, 254));
    }

    /**
     * Continues the test as a different network client (a fresh IP address with untouched budgets).
     */
    protected function switchToNewIp(): void
    {
        $this->client->setServerParameter('REMOTE_ADDR', self::randomIp());
    }

    protected function createMerchant(string $name = 'Test Merchant'): TestMerchant
    {
        $container = static::getContainer();

        /** @var ApiKeyService $keys */
        $keys = $container->get(ApiKeyService::class);
        $key = $keys->generate();

        $merchant = new Merchant($name, $key->prefix, $key->hash);

        /** @var EntityManagerInterface $em */
        $em = $container->get(EntityManagerInterface::class);
        $em->persist($merchant);
        $em->flush();

        return new TestMerchant($merchant->getId()->toRfc4122(), $key->plain);
    }

    /**
     * @param array<string, mixed>|null $json
     * @param array<string, string>     $headers
     */
    protected function request(
        string $method,
        string $uri,
        ?string $apiKey = null,
        ?array $json = null,
        array $headers = [],
    ): void {
        $server = ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'];

        if (null !== $apiKey) {
            $server['HTTP_X_API_KEY'] = $apiKey;
        }

        foreach ($headers as $name => $value) {
            $server['HTTP_'.strtoupper(str_replace('-', '_', $name))] = $value;
        }

        $this->client->request(
            $method,
            $uri,
            server: $server,
            content: null === $json ? null : json_encode($json, \JSON_THROW_ON_ERROR),
        );
    }

    /**
     * @param array<string, mixed>|null $json
     * @param array<string, string>     $headers
     *
     * @return array<string, mixed>
     */
    protected function requestJson(string $method, string $uri, ?string $apiKey = null, ?array $json = null, array $headers = []): array
    {
        $this->request($method, $uri, $apiKey, $json, $headers);

        return $this->responseJson();
    }

    /**
     * @return array<string, mixed>
     */
    protected function responseJson(): array
    {
        $content = (string) $this->client->getResponse()->getContent();

        /** @var array<string, mixed> $decoded */
        $decoded = '' === $content ? [] : json_decode($content, true, 512, \JSON_THROW_ON_ERROR);

        return $decoded;
    }

    protected function responseHeader(string $name): ?string
    {
        return $this->client->getResponse()->headers->get($name);
    }

    /**
     * Creates a product through the API and returns its decoded representation.
     *
     * @return array<string, mixed>
     */
    protected function createProduct(TestMerchant $merchant, ?string $sku = null, string $name = 'Test product'): array
    {
        $product = $this->requestJson('POST', '/api/v1/products', $merchant->apiKey, [
            'sku' => $sku ?? 'SKU-'.bin2hex(random_bytes(4)),
            'name' => $name,
        ]);
        self::assertResponseStatusCodeSame(201);

        return $product;
    }

    /**
     * @return array<string, mixed>
     */
    protected function createWarehouse(TestMerchant $merchant, ?string $code = null): array
    {
        $warehouse = $this->requestJson('POST', '/api/v1/warehouses', $merchant->apiKey, [
            'code' => $code ?? 'WH-'.strtoupper(bin2hex(random_bytes(3))),
            'name' => 'Test warehouse',
            'city' => 'Moscow',
        ]);
        self::assertResponseStatusCodeSame(201);

        return $warehouse;
    }
}
