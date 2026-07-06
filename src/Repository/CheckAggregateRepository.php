<?php

declare(strict_types=1);

namespace Nowo\UptimeMonitorBundle\Repository;

use DateTimeImmutable;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Nowo\UptimeMonitorBundle\Entity\CheckAggregate;
use Nowo\UptimeMonitorBundle\Entity\Monitor;
use Nowo\UptimeMonitorBundle\Enum\AggregatePeriod;

/**
 * @extends ServiceEntityRepository<CheckAggregate>
 */
class CheckAggregateRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, CheckAggregate::class);
    }

    public function findOneForBucket(
        Monitor $monitor,
        AggregatePeriod $period,
        DateTimeImmutable $periodStart,
    ): ?CheckAggregate {
        return $this->findOneBy([
            'monitor'     => $monitor,
            'period'      => $period,
            'periodStart' => $periodStart,
        ]);
    }

    /**
     * @return list<CheckAggregate>
     */
    public function findForMonitorInRange(
        Monitor $monitor,
        AggregatePeriod $period,
        DateTimeImmutable $from,
        DateTimeImmutable $to,
    ): array {
        /** @var list<CheckAggregate> $rows */
        $rows = $this->createQueryBuilder('a')
            ->andWhere('a.monitor = :monitor')
            ->andWhere('a.period = :period')
            ->andWhere('a.periodStart >= :from')
            ->andWhere('a.periodStart <= :to')
            ->setParameter('monitor', $monitor)
            ->setParameter('period', $period)
            ->setParameter('from', $from)
            ->setParameter('to', $to)
            ->orderBy('a.periodStart', 'ASC')
            ->getQuery()
            ->getResult();

        return $rows;
    }

    /**
     * @return list<CheckAggregate>
     */
    public function findTenantOverview(
        string $tenantSlug,
        AggregatePeriod $period,
        DateTimeImmutable $from,
        DateTimeImmutable $to,
    ): array {
        /** @var list<CheckAggregate> $rows */
        $rows = $this->createQueryBuilder('a')
            ->innerJoin('a.monitor', 'm')
            ->innerJoin('m.tenant', 't')
            ->andWhere('t.slug = :slug')
            ->andWhere('a.period = :period')
            ->andWhere('a.periodStart >= :from')
            ->andWhere('a.periodStart <= :to')
            ->setParameter('slug', $tenantSlug)
            ->setParameter('period', $period)
            ->setParameter('from', $from)
            ->setParameter('to', $to)
            ->orderBy('a.periodStart', 'ASC')
            ->getQuery()
            ->getResult();

        return $rows;
    }

    /**
     * @param list<int> $monitorIds
     *
     * @return array<int, list<CheckAggregate>>
     */
    public function findDailyAggregatesByMonitorIds(
        array $monitorIds,
        DateTimeImmutable $from,
        DateTimeImmutable $to,
    ): array {
        if ($monitorIds === []) {
            return [];
        }

        /** @var list<CheckAggregate> $rows */
        $rows = $this->createQueryBuilder('a')
            ->andWhere('a.monitor IN (:ids)')
            ->andWhere('a.period = :period')
            ->andWhere('a.periodStart >= :from')
            ->andWhere('a.periodStart <= :to')
            ->setParameter('ids', $monitorIds)
            ->setParameter('period', AggregatePeriod::Day)
            ->setParameter('from', $from)
            ->setParameter('to', $to)
            ->orderBy('a.periodStart', 'ASC')
            ->getQuery()
            ->getResult();

        $grouped = [];
        foreach ($rows as $row) {
            $monitorId = $row->getMonitor()->getId();
            if ($monitorId === null) {
                continue;
            }
            $grouped[$monitorId][] = $row;
        }

        return $grouped;
    }
}
