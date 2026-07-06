<?php

declare(strict_types=1);

namespace Nowo\UptimeMonitorBundle\Repository;

use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Nowo\UptimeMonitorBundle\Entity\Tag;
use Nowo\UptimeMonitorBundle\Entity\Tenant;

/**
 * @extends ServiceEntityRepository<Tag>
 */
final class TagRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Tag::class);
    }

    /**
     * @return list<Tag>
     */
    public function findByTenant(Tenant $tenant): array
    {
        return $this->createQueryBuilder('t')
            ->andWhere('t.tenant = :tenant')
            ->setParameter('tenant', $tenant)
            ->orderBy('t.name', 'ASC')
            ->getQuery()
            ->getResult();
    }
}
