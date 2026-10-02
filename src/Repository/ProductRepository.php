<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Product;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Product>
 */
class ProductRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Product::class);
    }

    /**
     * Tenant-scoped lookup: a merchant can never reach another merchant's product.
     */
    public function findOneForMerchant(string $merchantId, string $productId): ?Product
    {
        return $this->createQueryBuilder('p')
            ->andWhere('p.id = :id')
            ->andWhere('IDENTITY(p.merchant) = :merchant')
            ->setParameter('id', $productId)
            ->setParameter('merchant', $merchantId)
            ->getQuery()
            ->getOneOrNullResult();
    }

    public function existsForMerchant(string $merchantId, string $productId): bool
    {
        return null !== $this->createQueryBuilder('p')
            ->select('p.id')
            ->andWhere('p.id = :id')
            ->andWhere('IDENTITY(p.merchant) = :merchant')
            ->setParameter('id', $productId)
            ->setParameter('merchant', $merchantId)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * @return array{items: list<Product>, total: int}
     */
    public function paginate(string $merchantId, int $page, int $perPage, ?string $search, ?bool $active): array
    {
        $qb = $this->createQueryBuilder('p')
            ->andWhere('IDENTITY(p.merchant) = :merchant')
            ->setParameter('merchant', $merchantId);

        if (null !== $search && '' !== $search) {
            $qb->andWhere('LOWER(p.name) LIKE :search OR LOWER(p.sku) LIKE :search')
                ->setParameter('search', '%'.addcslashes(mb_strtolower($search), '%_\\').'%');
        }

        if (null !== $active) {
            $qb->andWhere('p.active = :active')->setParameter('active', $active);
        }

        $total = (int) (clone $qb)->select('COUNT(p.id)')->getQuery()->getSingleScalarResult();

        /** @var list<Product> $items */
        $items = $qb->orderBy('p.createdAt', 'DESC')
            ->addOrderBy('p.id', 'DESC')
            ->setFirstResult(($page - 1) * $perPage)
            ->setMaxResults($perPage)
            ->getQuery()
            ->getResult();

        return ['items' => $items, 'total' => $total];
    }
}
