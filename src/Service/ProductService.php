<?php

declare(strict_types=1);

namespace App\Service;

use App\Cache\CacheTags;
use App\Cache\CatalogCache;
use App\Dto\Request\CreateProductRequest;
use App\Dto\Request\ListProductsQuery;
use App\Dto\Request\UpdateProductRequest;
use App\Dto\Response\PageMeta;
use App\Dto\Response\ProductListView;
use App\Dto\Response\ProductView;
use App\Entity\Merchant;
use App\Entity\Product;
use App\Exception\ApiException;
use App\Http\ViewNormalizer;
use App\Repository\ProductPriceRepository;
use App\Repository\ProductRepository;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Catalog use cases. Reads are Cache-Aside; writes commit first and then invalidate by tags.
 * All methods return normalized arrays (the same shape that is cached and sent to the client).
 */
class ProductService
{
    private const TTL = 300;

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly ProductRepository $products,
        private readonly ProductPriceRepository $prices,
        private readonly CatalogCache $cache,
        private readonly ViewNormalizer $views,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function create(string $merchantId, CreateProductRequest $dto): array
    {
        $merchant = $this->em->getReference(Merchant::class, Uuid::fromString($merchantId));
        \assert($merchant instanceof Merchant);

        $product = new Product(
            $merchant,
            $dto->sku,
            $dto->name,
            $dto->description,
        );

        $this->em->persist($product);

        try {
            $this->em->flush();
        } catch (UniqueConstraintViolationException) {
            throw ApiException::conflict('sku_already_exists', \sprintf('A product with SKU "%s" already exists.', $dto->sku));
        }

        $this->cache->invalidate(CacheTags::merchantProducts($merchantId));

        return $this->views->normalize(ProductView::fromEntity($product, []));
    }

    /**
     * @return array<string, mixed>
     */
    public function get(string $merchantId, string $productId): array
    {
        return $this->cache->remember(
            CacheTags::productKey($merchantId, $productId),
            [CacheTags::product($productId), CacheTags::merchantProducts($merchantId)],
            self::TTL,
            function () use ($merchantId, $productId): array {
                $product = $this->products->findOneForMerchant($merchantId, $productId)
                    ?? throw ApiException::notFound('Product');

                return $this->view($product);
            },
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function list(string $merchantId, ListProductsQuery $query): array
    {
        $hash = md5(json_encode([$query->page, $query->perPage, $query->q, $query->active], \JSON_THROW_ON_ERROR));

        return $this->cache->remember(
            CacheTags::productListKey($merchantId, $hash),
            [CacheTags::merchantProducts($merchantId)],
            self::TTL,
            function () use ($merchantId, $query): array {
                ['items' => $items, 'total' => $total] = $this->products->paginate(
                    $merchantId,
                    $query->page,
                    $query->perPage,
                    $query->q,
                    $query->active,
                );

                $prices = $this->prices->findGroupedByProduct(
                    array_map(static fn (Product $p): string => $p->getId()->toRfc4122(), $items),
                );

                return $this->views->normalize(new ProductListView(
                    array_map(
                        static fn (Product $p): ProductView => ProductView::fromEntity($p, $prices[$p->getId()->toRfc4122()] ?? []),
                        $items,
                    ),
                    new PageMeta($query->page, $query->perPage, $total),
                ));
            },
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function update(string $merchantId, string $productId, UpdateProductRequest $dto): array
    {
        $product = $this->products->findOneForMerchant($merchantId, $productId)
            ?? throw ApiException::notFound('Product');

        $product->update($dto->name, $dto->description, $dto->active);
        $this->em->flush();

        $this->cache->invalidate(CacheTags::product($productId), CacheTags::merchantProducts($merchantId));

        return $this->view($product);
    }

    public function delete(string $merchantId, string $productId): void
    {
        $product = $this->products->findOneForMerchant($merchantId, $productId)
            ?? throw ApiException::notFound('Product');

        // Prices and stock rows are removed by ON DELETE CASCADE.
        $this->em->remove($product);
        $this->em->flush();

        $this->cache->invalidate(
            CacheTags::product($productId),
            CacheTags::stock($productId),
            CacheTags::merchantProducts($merchantId),
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function view(Product $product): array
    {
        $productId = $product->getId()->toRfc4122();

        return $this->views->normalize(
            ProductView::fromEntity($product, $this->prices->findGroupedByProduct([$productId])[$productId] ?? []),
        );
    }
}
