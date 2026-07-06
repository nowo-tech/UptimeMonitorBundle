<?php

declare(strict_types=1);

namespace Nowo\UptimeMonitorBundle\Repository;

use DateTimeImmutable;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Nowo\UptimeMonitorBundle\Entity\CheckResult;
use Nowo\UptimeMonitorBundle\Entity\Monitor;

use function count;

/**
 * @extends ServiceEntityRepository<CheckResult>
 */
class CheckResultRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, CheckResult::class);
    }

    public function findLatestForMonitor(Monitor $monitor): ?CheckResult
    {
        return $this->createQueryBuilder('c')
            ->andWhere('c.monitor = :monitor')
            ->setParameter('monitor', $monitor)
            ->orderBy('c.checkedAt', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * @param list<int> $monitorIds
     *
     * @return array<int, CheckResult> keyed by monitor id
     */
    public function findLatestByMonitorIds(array $monitorIds): array
    {
        if ($monitorIds === []) {
            return [];
        }

        /** @var list<CheckResult> $results */
        $results = $this->createQueryBuilder('c')
            ->innerJoin('c.monitor', 'm')
            ->andWhere('m.id IN (:ids)')
            ->setParameter('ids', $monitorIds)
            ->orderBy('c.checkedAt', 'DESC')
            ->getQuery()
            ->getResult();

        $latest = [];
        foreach ($results as $result) {
            $monitorId = $result->getMonitor()->getId();
            if ($monitorId !== null && !isset($latest[$monitorId])) {
                $latest[$monitorId] = $result;
            }
        }

        return $latest;
    }

    /**
     * @param list<int> $monitorIds
     *
     * @return array<int, list<CheckResult>> chronological (oldest first), capped per monitor
     */
    public function findRecentByMonitorIds(
        array $monitorIds,
        DateTimeImmutable $since,
        int $maxPerMonitor,
    ): array {
        if ($monitorIds === [] || $maxPerMonitor < 1) {
            return [];
        }

        /** @var list<CheckResult> $results */
        $results = $this->createQueryBuilder('c')
            ->innerJoin('c.monitor', 'm')
            ->andWhere('m.id IN (:ids)')
            ->andWhere('c.checkedAt >= :since')
            ->setParameter('ids', $monitorIds)
            ->setParameter('since', $since)
            ->orderBy('c.checkedAt', 'DESC')
            ->getQuery()
            ->getResult();

        return $this->groupAndCapChecks($results, $maxPerMonitor);
    }

    /**
     * @param list<int> $monitorIds
     *
     * @return array<int, list<CheckResult>>
     */
    public function findChecksSinceByMonitorIds(
        array $monitorIds,
        DateTimeImmutable $since,
    ): array {
        if ($monitorIds === []) {
            return [];
        }

        /** @var list<CheckResult> $results */
        $results = $this->createQueryBuilder('c')
            ->innerJoin('c.monitor', 'm')
            ->andWhere('m.id IN (:ids)')
            ->andWhere('c.checkedAt >= :since')
            ->setParameter('ids', $monitorIds)
            ->setParameter('since', $since)
            ->orderBy('c.checkedAt', 'ASC')
            ->getQuery()
            ->getResult();

        $grouped = [];
        foreach ($results as $result) {
            $monitorId = $result->getMonitor()->getId();
            if ($monitorId === null) {
                continue;
            }
            $grouped[$monitorId][] = $result;
        }

        return $grouped;
    }

    /**
     * @return list<CheckResult> chronological (oldest first)
     */
    public function findRecentForMonitor(
        Monitor $monitor,
        ?DateTimeImmutable $since,
        int $limit,
    ): array {
        $qb = $this->createQueryBuilder('c')
            ->andWhere('c.monitor = :monitor')
            ->setParameter('monitor', $monitor)
            ->orderBy('c.checkedAt', 'DESC')
            ->setMaxResults(max(1, $limit));

        if ($since !== null) {
            $qb->andWhere('c.checkedAt >= :since')
                ->setParameter('since', $since);
        }

        /** @var list<CheckResult> $results */
        $results = $qb->getQuery()->getResult();

        return array_reverse($results);
    }

    /**
     * @return list<CheckResult>
     */
    public function findChecksForMonitorSince(Monitor $monitor, DateTimeImmutable $since): array
    {
        /** @var list<CheckResult> $results */
        $results = $this->createQueryBuilder('c')
            ->andWhere('c.monitor = :monitor')
            ->andWhere('c.checkedAt >= :since')
            ->setParameter('monitor', $monitor)
            ->setParameter('since', $since)
            ->orderBy('c.checkedAt', 'ASC')
            ->getQuery()
            ->getResult();

        return $results;
    }

    /**
     * @return list<CheckResult> newest first
     */
    public function findRecentForTenant(string $tenantSlug, int $limit = 80): array
    {
        /** @var list<CheckResult> $results */
        $results = $this->createQueryBuilder('c')
            ->innerJoin('c.monitor', 'm')
            ->innerJoin('m.tenant', 't')
            ->andWhere('t.slug = :slug')
            ->setParameter('slug', $tenantSlug)
            ->orderBy('c.checkedAt', 'DESC')
            ->setMaxResults(max(1, $limit))
            ->getQuery()
            ->getResult();

        return $results;
    }

    /**
     * @param list<CheckResult> $results newest-first input
     *
     * @return array<int, list<CheckResult>> chronological per monitor
     */
    private function groupAndCapChecks(array $results, int $maxPerMonitor): array
    {
        $grouped = [];
        foreach ($results as $result) {
            $monitorId = $result->getMonitor()->getId();
            if ($monitorId === null) {
                continue;
            }

            if (!isset($grouped[$monitorId])) {
                $grouped[$monitorId] = [];
            }

            if (count($grouped[$monitorId]) >= $maxPerMonitor) {
                continue;
            }

            $grouped[$monitorId][] = $result;
        }

        foreach ($grouped as $monitorId => $checks) {
            $grouped[$monitorId] = array_reverse($checks);
        }

        return $grouped;
    }
}
