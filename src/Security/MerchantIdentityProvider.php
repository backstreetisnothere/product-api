<?php

declare(strict_types=1);

namespace App\Security;

use App\Cache\CacheTags;
use App\Cache\CatalogCache;
use App\Repository\MerchantRepository;

/**
 * Cache-Aside lookup of merchant identities (tag: merchant.<id>).
 * Deactivating a merchant must call CatalogCache::invalidate(CacheTags::merchant($id));
 * without it the change is effective after the TTL (60s) at the latest.
 */
class MerchantIdentityProvider
{
    private const TTL = 60;
    private const UUID_PATTERN = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/';

    public function __construct(
        private readonly CatalogCache $cache,
        private readonly MerchantRepository $merchants,
    ) {
    }

    public function findByApiKeyPrefix(string $prefix): ?MerchantIdentity
    {
        return $this->load(
            CacheTags::merchantIdentityByPrefixKey($prefix),
            fn () => $this->merchants->findOneByApiKeyPrefix($prefix),
        );
    }

    public function findById(string $merchantId): ?MerchantIdentity
    {
        if (1 !== preg_match(self::UUID_PATTERN, $merchantId)) {
            return null;
        }

        return $this->load(
            CacheTags::merchantIdentityByIdKey($merchantId),
            fn () => $this->merchants->findOneByIdentifier($merchantId),
        );
    }

    /**
     * @param callable(): ?\App\Entity\Merchant $finder
     */
    private function load(string $key, callable $finder): ?MerchantIdentity
    {
        /** @var array{id: string, name: string, api_key_hash: string, active: bool}|null $data */
        $data = $this->cache->remember(
            $key,
            static fn (?array $loaded): array => null === $loaded ? [] : [CacheTags::merchant($loaded['id'])],
            self::TTL,
            static function () use ($finder): ?array {
                $merchant = $finder();

                return null === $merchant ? null : MerchantIdentity::fromEntity($merchant)->toArray();
            },
        );

        return null === $data ? null : MerchantIdentity::fromArray($data);
    }
}
