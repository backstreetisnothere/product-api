<?php

declare(strict_types=1);

namespace App\Tests\Unit\Security;

use App\Security\ApiKeyService;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ApiKeyServiceTest extends TestCase
{
    public function testGeneratedKeysAreParsableAndVerifiable(): void
    {
        $service = new ApiKeyService('pepper');

        $key = $service->generate();
        $parts = $service->parse($key->plain);

        self::assertMatchesRegularExpression('/^sk_[a-f0-9]{12}_[a-f0-9]{48}$/', $key->plain);
        self::assertNotNull($parts);
        self::assertSame($key->prefix, $parts->prefix);
        self::assertSame($key->hash, $service->hash($key->plain));
    }

    public function testKeysAreUnique(): void
    {
        $service = new ApiKeyService('pepper');

        self::assertNotSame($service->generate()->plain, $service->generate()->plain);
    }

    public function testHashDependsOnThePepper(): void
    {
        $key = (new ApiKeyService('pepper-a'))->generate();

        self::assertNotSame($key->hash, (new ApiKeyService('pepper-b'))->hash($key->plain));
    }

    #[DataProvider('malformedKeys')]
    public function testMalformedKeysAreNotParsed(string $candidate): void
    {
        self::assertNull((new ApiKeyService('pepper'))->parse($candidate));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function malformedKeys(): iterable
    {
        yield 'empty' => [''];
        yield 'wrong prefix' => ['pk_'.str_repeat('a', 12).'_'.str_repeat('b', 48)];
        yield 'short secret' => ['sk_'.str_repeat('a', 12).'_'.str_repeat('b', 47)];
        yield 'uppercase hex' => ['sk_'.str_repeat('A', 12).'_'.str_repeat('B', 48)];
        yield 'trailing garbage' => ['sk_'.str_repeat('a', 12).'_'.str_repeat('b', 48).'x'];
    }
}
