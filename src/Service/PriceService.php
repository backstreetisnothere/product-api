<?php

declare(strict_types=1);

namespace App\Service;

use App\Cache\CacheTags;
use App\Cache\CatalogCache;
use App\Dto\Response\PriceView;
use App\Exception\ApiException;
use App\Http\ViewNormalizer;
use App\Repository\ProductPriceRepository;
use App\Repository\ProductRepository;

class PriceService
{
    public function __construct(
        private readonly ProductRepository $products,
        private readonly ProductPriceRepository $prices,
        private readonly ProductService $catalog,
        private readonly CatalogCache $cache,
        private readonly ViewNormalizer $views,
    ) {
    }

    /**
     * Prices are embedded into the (cached) product view.
     *
     * @return array{data: list<mixed>}
     */
    public function list(string $merchantId, string $productId): array
    {
        /** @var list<mixed> $prices */
        $prices = $this->catalog->get($merchantId, $productId)['prices'];

        return ['data' => $prices];
    }

    /**
     * @return array<string, mixed>
     */
    public function set(string $merchantId, string $productId, string $currency, int $amount): array
    {
        $this->assertProductExists($merchantId, $productId);

        $now = new \DateTimeImmutable();
        $this->prices->upsert($productId, $currency, $amount, $now);

        $this->invalidate($merchantId, $productId);

        return $this->views->normalize(new PriceView($currency, $amount, $now));
    }

    public function delete(string $merchantId, string $productId, string $currency): void
    {
        $this->assertProductExists($merchantId, $productId);

        if (!$this->prices->delete($productId, $currency)) {
            throw ApiException::notFound('Price');
        }

        $this->invalidate($merchantId, $productId);
    }

    private function assertProductExists(string $merchantId, string $productId): void
    {
        if (!$this->products->existsForMerchant($merchantId, $productId)) {
            throw ApiException::notFound('Product');
        }
    }

    private function invalidate(string $merchantId, string $productId): void
    {
        $this->cache->invalidate(CacheTags::product($productId), CacheTags::merchantProducts($merchantId));
    }
}
