<?php

declare(strict_types=1);

namespace Nowo\UptimeMonitorBundle\Repository;

use DateTimeImmutable;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\Persistence\ManagerRegistry;
use Nowo\UptimeMonitorBundle\Doctrine\UptimeRowLock;
use Nowo\UptimeMonitorBundle\Doctrine\UptimeSavepoint;
use Nowo\UptimeMonitorBundle\Entity\Monitor;
use Nowo\UptimeMonitorBundle\Enum\MonitorType;
use SortDirection;

/**
 * @extends ServiceEntityRepository<Monitor>
 */
class MonitorRepository extends ServiceEntityRepository implements MonitorRepositoryInterface
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
     * The monitor with this id when it belongs to the tenant with this slug, else null (since 1.35). The slug is
     * re-checked byte for byte after the read, so a case- or accent-insensitive collation never matches another tenant.
     */
    public function findInTenant(string $tenantSlug, int $id): ?Monitor
    {
        if ($id < 1 || $tenantSlug === '') {
            return null;
        }

        $monitor = $this->createQueryBuilder('m')
            ->addSelect('t')
            ->innerJoin('m.tenant', 't')
            ->andWhere('m.id = :id')
            ->andWhere('t.slug = :slug')
            ->setParameter('id', $id)
            ->setParameter('slug', $tenantSlug)
            ->getQuery()
            ->getOneOrNullResult();

        return $monitor instanceof Monitor && $monitor->getTenant()->getSlug() === $tenantSlug ? $monitor : null;
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
     * Claims a due monitor for one check (since 1.29, Beacon spec 239): moves its next check time from the value the
     * scan read to `$lease`, only if no other process changed it meanwhile and it is still due at `$now` (a monitor
     * whose next check is already in the future is never claimed again, audit QK-L3). True when this call claimed it; false when
     * another scheduler (or a check) got there first, so concurrent schedulers never check one monitor twice. One
     * statement, in a savepoint inside a transaction of the application.
     *
     * @param int $id Monitor id
     * @param DateTimeImmutable|null $seen The next check time the scan read
     * @param DateTimeImmutable $lease The next check time while the check runs (the check stores the real one)
     * @param DateTimeImmutable|null $now The scan time the monitor must still be due at (null: no due check)
     */
    public function claimDue(int $id, ?DateTimeImmutable $seen, DateTimeImmutable $lease, ?DateTimeImmutable $now = null): bool
    {
        $entityManager = $this->getEntityManager();
        $query         = $entityManager->createQueryBuilder()
            ->update(Monitor::class, 'm')
            ->set('m.nextCheckAt', ':lease')
            ->where('m.id = :id')
            ->setParameter('lease', $lease, Types::DATETIME_IMMUTABLE)
            ->setParameter('id', $id);
        if ($seen instanceof DateTimeImmutable) {
            $query->andWhere('m.nextCheckAt = :seen')->setParameter('seen', $seen, Types::DATETIME_IMMUTABLE);
            if ($now instanceof DateTimeImmutable) {
                $query->andWhere('m.nextCheckAt <= :now')->setParameter('now', $now, Types::DATETIME_IMMUTABLE);
            }
        } else {
            $query->andWhere('m.nextCheckAt IS NULL');
        }

        return (int) UptimeSavepoint::run($entityManager->getConnection(), static fn (): mixed => $query->getQuery()->execute()) === 1;
    }

    /**
     * The monitor row locked for the running unit of work and read again (since 1.29, Beacon spec 239): the writers
     * of the row queue up until the transaction ends. Inside a transaction only.
     *
     * @return Monitor|null Null when the row does not exist (any more)
     */
    public function lockForWrite(int $id): ?Monitor
    {
        return UptimeRowLock::lock($this->getEntityManager(), Monitor::class, $id);
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
