<?php

declare(strict_types=1);

namespace Nowo\UptimeMonitorBundle\Repository;

use DateTimeImmutable;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Nowo\UptimeMonitorBundle\Entity\Monitor;
use Nowo\UptimeMonitorBundle\Enum\MonitorType;
use SortDirection;

/**
 * @extends ServiceEntityRepository<Monitor>
 */
class MonitorRepository extends ServiceEntityRepository
{
    /**
     * Creates the repository of monitors.
     *
     * @param ManagerRegistry $registry Doctrine registry
     */
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Monitor::class);
    }

    /**
     * Number of the tenant's monitors that run checks (groups not counted).
     */
    public function countChecksByTenantSlug(string $tenantSlug): int
    {
        return (int) $this->createQueryBuilder('m')
            ->select('COUNT(m.id)')
            ->innerJoin('m.tenant', 't')
            ->andWhere('t.slug = :slug')
            ->andWhere('m.type <> :group')
            ->setParameter('slug', $tenantSlug)
            ->setParameter('group', MonitorType::Group)
            ->getQuery()
            ->getSingleScalarResult();
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
            ->orderBy('m.name', SortDirection::Ascending)
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
            ->orderBy('m.name', SortDirection::Ascending)
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
            ->orderBy('m.name', SortDirection::Ascending)
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
            // A disabled tenant's monitors never run (SEC-004 L2).
            ->innerJoin('m.tenant', 't')
            ->andWhere('t.enabled = true')
            ->andWhere('m.paused = false')
            ->andWhere('m.nextCheckAt IS NULL OR m.nextCheckAt <= :now')
            ->setParameter('now', $now)
            ->orderBy('m.nextCheckAt', SortDirection::Ascending)
            ->getQuery()
            ->getResult();

        return $monitors;
    }

    /**
     * The push monitor of an ingest URL key, with its tenant (null when no push monitor has that key).
     */
    public function findOneByPushKey(string $key): ?Monitor
    {
        /** @var Monitor|null $monitor */
        $monitor = $this->createQueryBuilder('m')
            ->addSelect('t')
            ->innerJoin('m.tenant', 't')
            ->andWhere('m.pushKey = :key')
            ->andWhere('m.type = :type')
            ->setParameter('key', $key)
            ->setParameter('type', MonitorType::Push)
            ->getQuery()
            ->getOneOrNullResult();

        return $monitor;
    }
}
