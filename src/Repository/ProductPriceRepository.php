<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\ProductPrice;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Types\Types;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Uid\Uuid;

/**
 * @extends ServiceEntityRepository<ProductPrice>
 */
class ProductPriceRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ProductPrice::class);
    }

    /**
     * Loads prices of many products with a single query (avoids N+1 on list endpoints).
     *
     * @param list<string> $productIds
     *
     * @return array<string, list<ProductPrice>> prices grouped by product id
     */
    public function findGroupedByProduct(array $productIds): array
    {
        if ([] === $productIds) {
            return [];
        }

        /** @var list<ProductPrice> $prices */
        $prices = $this->createQueryBuilder('pp')
            ->addSelect('p')
            ->join('pp.product', 'p')
            ->andWhere('IDENTITY(pp.product) IN (:ids)')
            ->setParameter('ids', $productIds, ArrayParameterType::STRING)
            ->orderBy('pp.currency', 'ASC')
            ->getQuery()
            ->getResult();

        $grouped = [];
        foreach ($prices as $price) {
            $grouped[$price->getProduct()->getId()->toRfc4122()][] = $price;
        }

        return $grouped;
    }

    /**
     * Atomic insert-or-update, safe under concurrent writers (no read-modify-write race).
     */
    public function upsert(string $productId, string $currency, int $amount, \DateTimeImmutable $now): void
    {
        $this->getEntityManager()->getConnection()->executeStatement(
            'INSERT INTO product_prices (id, product_id, currency, amount, updated_at)
             VALUES (:id, :product, :currency, :amount, :now)
             ON CONFLICT (product_id, currency)
             DO UPDATE SET amount = EXCLUDED.amount, updated_at = EXCLUDED.updated_at',
            [
                'id' => Uuid::v7()->toRfc4122(),
                'product' => $productId,
                'currency' => $currency,
                'amount' => $amount,
                'now' => $now,
            ],
            ['now' => Types::DATETIME_IMMUTABLE],
        );
    }

    public function delete(string $productId, string $currency): bool
    {
        $affected = $this->getEntityManager()->getConnection()->executeStatement(
            'DELETE FROM product_prices WHERE product_id = :product AND currency = :currency',
            ['product' => $productId, 'currency' => $currency],
        );

        return (int) $affected > 0;
    }
}
