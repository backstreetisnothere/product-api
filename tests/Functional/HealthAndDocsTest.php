<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Tests\Support\ApiTestCase;

final class HealthAndDocsTest extends ApiTestCase
{
    public function testHealthReportsDependencies(): void
    {
        $body = $this->requestJson('GET', '/api/v1/health');

        self::assertResponseStatusCodeSame(200);
        self::assertSame(['status' => 'ok', 'checks' => ['database' => 'up', 'redis' => 'up']], $body);
    }

    public function testOpenApiDocumentDescribesTheApi(): void
    {
        $spec = $this->requestJson('GET', '/api/doc.json');

        self::assertResponseStatusCodeSame(200);
        self::assertStringStartsWith('3.', $spec['openapi']);
        self::assertSame('Seller API', $spec['info']['title']);

        $paths = $spec['paths'];
        foreach ([
            '/api/v1/auth/token',
            '/api/v1/products',
            '/api/v1/products/{id}',
            '/api/v1/products/{productId}/prices/{currency}',
            '/api/v1/products/{productId}/stock',
            '/api/v1/products/{productId}/stock/{warehouseId}/adjustments',
            '/api/v1/warehouses',
            '/api/v1/warehouses/{id}',
        ] as $path) {
            self::assertArrayHasKey($path, $paths, \sprintf('Path %s is documented.', $path));
        }

        self::assertArrayHasKey('ApiKeyAuth', $spec['components']['securitySchemes']);
        self::assertArrayHasKey('BearerAuth', $spec['components']['securitySchemes']);
        self::assertArrayHasKey('Problem', $spec['components']['schemas']);
    }

    public function testDocumentationMarksIdempotencyAndPublicOperations(): void
    {
        $spec = $this->requestJson('GET', '/api/doc.json');
        $paths = $spec['paths'];

        $adjust = $paths['/api/v1/products/{productId}/stock/{warehouseId}/adjustments']['post'];
        $header = $this->findParameter($adjust['parameters'], $spec['components']['parameters'], 'Idempotency-Key');
        self::assertNotNull($header, 'The adjustment operation documents the Idempotency-Key header.');
        self::assertTrue($header['required']);

        $create = $paths['/api/v1/products']['post'];
        $optional = $this->findParameter($create['parameters'], $spec['components']['parameters'], 'Idempotency-Key');
        self::assertNotNull($optional);
        self::assertFalse($optional['required']);

        self::assertSame([], $paths['/api/v1/auth/token']['post']['security'], 'Token exchange is public.');
    }

    public function testSwaggerUiIsServed(): void
    {
        $this->client->request('GET', '/api/doc');

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('swagger', strtolower((string) $this->client->getResponse()->getContent()));
    }

    /**
     * Finds an operation parameter by name, resolving "#/components/parameters/..." references.
     *
     * @param list<array<string, mixed>>          $parameters
     * @param array<string, array<string, mixed>> $components
     *
     * @return array<string, mixed>|null
     */
    private function findParameter(array $parameters, array $components, string $name): ?array
    {
        foreach ($parameters as $parameter) {
            if (isset($parameter['$ref'])) {
                $parameter = $components[basename((string) $parameter['$ref'])];
            }

            if (($parameter['name'] ?? null) === $name) {
                return $parameter;
            }
        }

        return null;
    }
}
