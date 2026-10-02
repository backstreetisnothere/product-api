<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Merchant;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Merchant>
 */
class MerchantRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Merchant::class);
    }

    public function findOneByApiKeyPrefix(string $prefix): ?Merchant
    {
        return $this->findOneBy(['apiKeyPrefix' => $prefix]);
    }

    public function findOneByIdentifier(string $id): ?Merchant
    {
        return $this->find($id);
    }
}
