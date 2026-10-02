<?php

declare(strict_types=1);

namespace App\Service;

use App\Cache\CacheTags;
use App\Cache\CatalogCache;
use App\Dto\Request\CreateWarehouseRequest;
use App\Dto\Request\UpdateWarehouseRequest;
use App\Dto\Response\WarehouseListView;
use App\Dto\Response\WarehouseView;
use App\Entity\Merchant;
use App\Entity\Warehouse;
use App\Exception\ApiException;
use App\Http\ViewNormalizer;
use App\Repository\WarehouseRepository;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;

class WarehouseService
{
    private const TTL = 600;

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly WarehouseRepository $warehouses,
        private readonly CatalogCache $cache,
        private readonly ViewNormalizer $views,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function create(string $merchantId, CreateWarehouseRequest $dto): array
    {
        $merchant = $this->em->getReference(Merchant::class, Uuid::fromString($merchantId));
        \assert($merchant instanceof Merchant);

        $warehouse = new Warehouse(
            $merchant,
            $dto->code,
            $dto->name,
            $dto->city,
        );

        $this->em->persist($warehouse);

        try {
            $this->em->flush();
        } catch (UniqueConstraintViolationException) {
            throw ApiException::conflict('warehouse_code_already_exists', \sprintf('A warehouse with code "%s" already exists.', $dto->code));
        }

        $this->cache->invalidate(CacheTags::merchantWarehouses($merchantId));

        return $this->views->normalize(WarehouseView::fromEntity($warehouse));
    }

    /**
     * @return array<string, mixed>
     */
    public function list(string $merchantId): array
    {
        return $this->cache->remember(
            CacheTags::warehouseListKey($merchantId),
            [CacheTags::merchantWarehouses($merchantId)],
            self::TTL,
            fn (): array => $this->views->normalize(new WarehouseListView(
                array_map(WarehouseView::fromEntity(...), $this->warehouses->findAllForMerchant($merchantId)),
            )),
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function get(string $merchantId, string $warehouseId): array
    {
        return $this->cache->remember(
            CacheTags::warehouseKey($merchantId, $warehouseId),
            [CacheTags::warehouse($warehouseId), CacheTags::merchantWarehouses($merchantId)],
            self::TTL,
            fn (): array => $this->views->normalize(WarehouseView::fromEntity(
                $this->warehouses->findOneForMerchant($merchantId, $warehouseId) ?? throw ApiException::notFound('Warehouse'),
            )),
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function update(string $merchantId, string $warehouseId, UpdateWarehouseRequest $dto): array
    {
        $warehouse = $this->warehouses->findOneForMerchant($merchantId, $warehouseId)
            ?? throw ApiException::notFound('Warehouse');

        $warehouse->update($dto->name, $dto->city);
        $this->em->flush();

        $this->invalidate($merchantId, $warehouseId);

        return $this->views->normalize(WarehouseView::fromEntity($warehouse));
    }

    public function delete(string $merchantId, string $warehouseId): void
    {
        $warehouse = $this->warehouses->findOneForMerchant($merchantId, $warehouseId)
            ?? throw ApiException::notFound('Warehouse');

        // Stock rows are removed by ON DELETE CASCADE; cached stock views carry the warehouse tag.
        $this->em->remove($warehouse);
        $this->em->flush();

        $this->invalidate($merchantId, $warehouseId);
    }

    private function invalidate(string $merchantId, string $warehouseId): void
    {
        $this->cache->invalidate(CacheTags::warehouse($warehouseId), CacheTags::merchantWarehouses($merchantId));
    }
}
