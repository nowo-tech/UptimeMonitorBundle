<?php

declare(strict_types=1);

namespace Nowo\UptimeMonitorBundle\Query;

use DateTimeImmutable;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\QueryBuilder;
use Nowo\UptimeMonitorBundle\Entity\CheckAggregate;
use Nowo\UptimeMonitorBundle\Enum\AggregatePeriod;
use SortDirection;

use function is_numeric;
use function max;
use function min;

/**
 * Default {@see CheckAggregateQueryInterface} (since 1.36): DQL over the bundle's own entity manager with scalar
 * results (no entity is loaded or kept: FrankenPHP worker mode safe), stateless. Every row carries its tenant slug and
 * is checked against the scope again in PHP with `===` (a look-alike tenant that the column collation matches is never
 * read). Plain reads: no lock is taken, a concurrent rollup never waits for a report.
 */
final readonly class DoctrineCheckAggregateQuery implements CheckAggregateQueryInterface
{
    /**
     * @param EntityManagerInterface $entityManager The bundle's manager (reads only)
     */
    public function __construct(
        private EntityManagerInterface $entityManager,
    ) {
    }

    /**
     * {@inheritDoc}
     */
    public function totalsByMonitor(MonitorScope $scope, DateTimeImmutable $from, DateTimeImmutable $to, AggregatePeriod $period = AggregatePeriod::Day): array
    {
        if ($scope->isEmpty() || $from >= $to) {
            return [];
        }

        /** @var list<array<string, mixed>> $rows */
        $rows = $this->window($scope, $from, $to, $period)
            ->select('m.id AS monitorId', 't.slug AS tenantSlug', 'COUNT(a.id) AS buckets', 'SUM(a.checksTotal) AS checksTotal', 'SUM(a.checksUp) AS checksUp')
            ->addSelect('SUM(a.checksDown) AS checksDown', 'SUM(a.latencyAvgMs * a.checksTotal) AS latencyWeighted')
            ->groupBy('m.id')
            ->addGroupBy('t.slug')
            ->orderBy('m.id', SortDirection::Ascending)
            ->getQuery()
            ->getArrayResult();

        $totals = [];
        foreach ($rows as $row) {
            $monitorId = ReadRow::int($row['monitorId'] ?? 0);
            $slug      = ReadRow::string($row['tenantSlug'] ?? '');
            if (!$scope->covers($slug, $monitorId)) {
                continue;
            }
            $totals[$monitorId] = new CheckAggregateTotals(
                $monitorId,
                $slug,
                ReadRow::int($row['buckets'] ?? 0),
                ReadRow::int($row['checksTotal'] ?? 0),
                ReadRow::int($row['checksUp'] ?? 0),
                ReadRow::int($row['checksDown'] ?? 0),
                ReadRow::int($row['latencyWeighted'] ?? 0),
            );
        }

        return $totals;
    }

    /**
     * {@inheritDoc}
     */
    public function list(MonitorScope $scope, DateTimeImmutable $from, DateTimeImmutable $to, AggregatePeriod $period = AggregatePeriod::Day, int $limit = 100, int $offset = 0): array
    {
        if ($scope->isEmpty() || $from >= $to) {
            return [];
        }

        /** @var list<array<string, mixed>> $rows */
        $rows = $this->window($scope, $from, $to, $period)
            ->select('a.id AS id', 'm.id AS monitorId', 't.slug AS tenantSlug', 'a.periodStart AS periodStart', 'a.checksTotal AS checksTotal')
            ->addSelect('a.checksUp AS checksUp', 'a.checksDown AS checksDown', 'a.uptimeRatio AS uptimeRatio', 'a.latencyAvgMs AS latencyAvgMs')
            ->orderBy('a.periodStart', SortDirection::Ascending)
            ->addOrderBy('m.id', SortDirection::Ascending)
            ->addOrderBy('a.id', SortDirection::Ascending)
            ->setMaxResults(max(1, min(self::MAX_LIMIT, $limit)))
            ->setFirstResult(max(0, $offset))
            ->getQuery()
            ->getArrayResult();

        $views = [];
        foreach ($rows as $row) {
            $monitorId = ReadRow::int($row['monitorId'] ?? 0);
            $slug      = ReadRow::string($row['tenantSlug'] ?? '');
            $start     = ReadRow::date($row['periodStart'] ?? null);
            if (!$start instanceof DateTimeImmutable || !$scope->covers($slug, $monitorId)) {
                continue;
            }
            $ratio   = $row['uptimeRatio'] ?? 0;
            $views[] = new CheckAggregateView(
                ReadRow::int($row['id'] ?? 0),
                $monitorId,
                $slug,
                $period,
                $start,
                ReadRow::int($row['checksTotal'] ?? 0),
                ReadRow::int($row['checksUp'] ?? 0),
                ReadRow::int($row['checksDown'] ?? 0),
                is_numeric($ratio) ? (float) $ratio : 0.0,
                ReadRow::int($row['latencyAvgMs'] ?? 0),
            );
        }

        return $views;
    }

    /**
     * {@inheritDoc}
     */
    public function count(MonitorScope $scope, DateTimeImmutable $from, DateTimeImmutable $to, AggregatePeriod $period = AggregatePeriod::Day): int
    {
        $total = 0;
        foreach ($this->totalsByMonitor($scope, $from, $to, $period) as $totals) {
            $total += $totals->buckets;
        }

        return $total;
    }

    /**
     * The buckets of the window and the scope (no select yet).
     */
    private function window(MonitorScope $scope, DateTimeImmutable $from, DateTimeImmutable $to, AggregatePeriod $period): QueryBuilder
    {
        $builder = $this->entityManager->createQueryBuilder()
            ->from(CheckAggregate::class, 'a')
            ->innerJoin('a.monitor', 'm')
            ->innerJoin('m.tenant', 't')
            ->andWhere('a.period = :period')
            ->andWhere('a.periodStart >= :from')
            ->andWhere('a.periodStart < :to')
            ->setParameter('period', $period->value)
            ->setParameter('from', $from, Types::DATETIME_IMMUTABLE)
            ->setParameter('to', $to, Types::DATETIME_IMMUTABLE);

        return MonitorScopeFilter::apply($builder, $scope);
    }
}
