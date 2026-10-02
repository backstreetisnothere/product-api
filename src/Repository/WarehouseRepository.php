<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Warehouse;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Warehouse>
 */
class WarehouseRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Warehouse::class);
    }

    public function findOneForMerchant(string $merchantId, string $warehouseId): ?Warehouse
    {
        return $this->createQueryBuilder('w')
            ->andWhere('w.id = :id')
            ->andWhere('IDENTITY(w.merchant) = :merchant')
            ->setParameter('id', $warehouseId)
            ->setParameter('merchant', $merchantId)
            ->getQuery()
            ->getOneOrNullResult();
    }

    public function existsForMerchant(string $merchantId, string $warehouseId): bool
    {
        return null !== $this->createQueryBuilder('w')
            ->select('w.id')
            ->andWhere('w.id = :id')
            ->andWhere('IDENTITY(w.merchant) = :merchant')
            ->setParameter('id', $warehouseId)
            ->setParameter('merchant', $merchantId)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * @return list<Warehouse>
     */
    public function findAllForMerchant(string $merchantId): array
    {
        /** @var list<Warehouse> $warehouses */
        $warehouses = $this->createQueryBuilder('w')
            ->andWhere('IDENTITY(w.merchant) = :merchant')
            ->setParameter('merchant', $merchantId)
            ->orderBy('w.code', 'ASC')
            ->getQuery()
            ->getResult();

        return $warehouses;
    }
}
