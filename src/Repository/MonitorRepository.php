<?php

declare(strict_types=1);

namespace Nowo\UptimeMonitorBundle\Repository;

use DateTimeImmutable;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Nowo\UptimeMonitorBundle\Entity\Monitor;
use Nowo\UptimeMonitorBundle\Enum\MonitorType;

/**
 * @extends ServiceEntityRepository<Monitor>
 */
class MonitorRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Monitor::class);
    }

    /**
     * @return list<Monitor>
     */
    public function findByTenantSlug(string $tenantSlug): array
    {
        /** @var list<Monitor> $monitors */
        $monitors = $this->createQueryBuilder('m')
            ->innerJoin('m.tenant', 't')
            ->andWhere('t.slug = :slug')
            ->setParameter('slug', $tenantSlug)
            ->orderBy('m.name', 'ASC')
            ->getQuery()
            ->getResult();

        return $monitors;
    }

    /**
     * @return list<Monitor>
     */
    public function findGroupsByTenantSlug(string $tenantSlug): array
    {
        /** @var list<Monitor> $monitors */
        $monitors = $this->createQueryBuilder('m')
            ->innerJoin('m.tenant', 't')
            ->andWhere('t.slug = :slug')
            ->andWhere('m.type = :groupType')
            ->setParameter('slug', $tenantSlug)
            ->setParameter('groupType', MonitorType::Group)
            ->orderBy('m.name', 'ASC')
            ->getQuery()
            ->getResult();

        return $monitors;
    }

    /**
     * @return list<Monitor>
     */
    public function findChildrenOf(int $parentId): array
    {
        /** @var list<Monitor> $monitors */
        $monitors = $this->createQueryBuilder('m')
            ->innerJoin('m.parent', 'p')
            ->andWhere('p.id = :parentId')
            ->setParameter('parentId', $parentId)
            ->orderBy('m.name', 'ASC')
            ->getQuery()
            ->getResult();

        return $monitors;
    }

    /**
     * @return list<Monitor>
     */
    public function findDue(DateTimeImmutable $now): array
    {
        /** @var list<Monitor> $monitors */
        $monitors = $this->createQueryBuilder('m')
            ->andWhere('m.paused = false')
            ->andWhere('m.nextCheckAt IS NULL OR m.nextCheckAt <= :now')
            ->setParameter('now', $now)
            ->orderBy('m.nextCheckAt', 'ASC')
            ->getQuery()
            ->getResult();

        return $monitors;
    }
}
