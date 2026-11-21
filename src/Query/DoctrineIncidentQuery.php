<?php

declare(strict_types=1);

namespace Nowo\UptimeMonitorBundle\Query;

use DateTimeImmutable;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\QueryBuilder;
use Nowo\UptimeMonitorBundle\Entity\Incident;
use Nowo\UptimeMonitorBundle\Entity\Monitor;
use Nowo\UptimeMonitorBundle\Enum\CheckStatus;
use Nowo\UptimeMonitorBundle\Security\SecretRedactor;
use SortDirection;

use function array_chunk;
use function array_keys;
use function is_string;
use function max;
use function min;
use function sprintf;

/**
 * Default {@see IncidentQueryInterface} (since 1.36): DQL (and, for the downtime sums, one native statement per 1,000
 * monitors) over the bundle's own entity manager with scalar results, so no entity is loaded or kept (FrankenPHP
 * worker mode safe); stateless. Every row carries its tenant slug and is checked against the scope again in PHP with
 * `===` (a look-alike tenant that the column collation matches is never read). Plain reads: no lock is taken.
 */
final readonly class DoctrineIncidentQuery implements IncidentQueryInterface
{
    /** Monitors per downtime statement (bound parameters stay far below every platform's limit). */
    public const DOWNTIME_CHUNK = 1000;

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
    public function list(MonitorScope $scope, DateTimeImmutable $from, DateTimeImmutable $to, ?bool $open = null, int $limit = 100, int $offset = 0): array
    {
        if ($scope->isEmpty() || $from >= $to) {
            return [];
        }

        /** @var list<array<string, mixed>> $rows */
        $rows = $this->window($scope, $from, $to, $open)
            ->select('i.id AS id', 'm.id AS monitorId', 't.slug AS tenantSlug', 'i.triggerStatus AS triggerStatus', 'i.startedAt AS startedAt', 'i.endedAt AS endedAt', 'i.message AS message')
            ->orderBy('i.startedAt', SortDirection::Descending)
            ->addOrderBy('i.id', SortDirection::Descending)
            ->setMaxResults(max(1, min(self::MAX_LIMIT, $limit)))
            ->setFirstResult(max(0, $offset))
            ->getQuery()
            ->getArrayResult();

        $views = [];
        foreach ($rows as $row) {
            $monitorId = ReadRow::int($row['monitorId'] ?? 0);
            $slug      = ReadRow::string($row['tenantSlug'] ?? '');
            $started   = ReadRow::date($row['startedAt'] ?? null);
            $status    = $row['triggerStatus'] ?? null;
            $status    = $status instanceof CheckStatus ? $status : CheckStatus::tryFrom(ReadRow::string($status));
            if (!$started instanceof DateTimeImmutable || !$status instanceof CheckStatus || !$scope->covers($slug, $monitorId)) {
                continue;
            }
            $message = $row['message'] ?? null;
            $views[] = new IncidentView(
                ReadRow::int($row['id'] ?? 0),
                $monitorId,
                $slug,
                $status,
                $started,
                ReadRow::date($row['endedAt'] ?? null),
                is_string($message) ? SecretRedactor::maskUrlsInText($message) : null,
            );
        }

        return $views;
    }

    /**
     * {@inheritDoc}
     */
    public function count(MonitorScope $scope, DateTimeImmutable $from, DateTimeImmutable $to, ?bool $open = null): int
    {
        if ($scope->isEmpty() || $from >= $to) {
            return 0;
        }

        // Counted per monitor, and only the monitors of the scope compared byte for byte are summed.
        /** @var list<array<string, mixed>> $rows */
        $rows = $this->window($scope, $from, $to, $open)
            ->select('m.id AS monitorId', 't.slug AS tenantSlug', 'COUNT(i.id) AS n')
            ->groupBy('m.id')
            ->addGroupBy('t.slug')
            ->getQuery()
            ->getArrayResult();

        $total = 0;
        foreach ($rows as $row) {
            if ($scope->covers(ReadRow::string($row['tenantSlug'] ?? ''), ReadRow::int($row['monitorId'] ?? 0))) {
                $total += ReadRow::int($row['n'] ?? 0);
            }
        }

        return $total;
    }

    /**
     * {@inheritDoc}
     */
    public function downtimeByMonitor(MonitorScope $scope, DateTimeImmutable $from, DateTimeImmutable $to, DateTimeImmutable $now): array
    {
        if ($scope->isEmpty() || $from >= $to) {
            return [];
        }

        // The platform is checked before the first query: an unsupported one throws without reading anything.
        $connection = $this->entityManager->getConnection();
        $platform   = $connection->getDatabasePlatform();
        $metadata   = $this->entityManager->getClassMetadata(Incident::class);
        $monitorCol = $metadata->getSingleAssociationJoinColumnName('monitor');
        $started    = $metadata->getColumnName('startedAt');
        $ended      = $metadata->getColumnName('endedAt');
        $sql        = sprintf(
            'SELECT %1$s AS monitor_id, COUNT(*) AS n, SUM(%2$s) AS seconds FROM %3$s WHERE %1$s IN (:ids) AND %4$s < %6$s AND (%5$s IS NULL OR %5$s >= %7$s) GROUP BY %1$s',
            $monitorCol,
            IncidentDowntimeSql::seconds($platform, $started, $ended),
            $metadata->getTableName(),
            $started,
            $ended,
            IncidentDowntimeSql::bound($platform, 'to'),
            IncidentDowntimeSql::bound($platform, 'from'),
        );

        // The monitors of the scope first (exact slugs), then the sums over their incidents by id.
        /** @var list<array<string, mixed>> $rows */
        $rows = MonitorScopeFilter::apply(
            $this->entityManager->createQueryBuilder()->select('m.id AS monitorId', 't.slug AS tenantSlug')->from(Monitor::class, 'm')->innerJoin('m.tenant', 't'),
            $scope,
        )->getQuery()->getArrayResult();
        $slugs = [];
        foreach ($rows as $row) {
            $monitorId = ReadRow::int($row['monitorId'] ?? 0);
            $slug      = ReadRow::string($row['tenantSlug'] ?? '');
            if ($scope->covers($slug, $monitorId)) {
                $slugs[$monitorId] = $slug;
            }
        }
        if ($slugs === []) {
            return [];
        }

        $downtime = [];
        foreach (array_chunk(array_keys($slugs), self::DOWNTIME_CHUNK) as $ids) {
            $result = $connection->executeQuery(
                $sql,
                ['ids' => $ids, 'from' => $from, 'to' => $to, 'now' => $now],
                ['ids' => ArrayParameterType::INTEGER, 'from' => Types::DATETIME_IMMUTABLE, 'to' => Types::DATETIME_IMMUTABLE, 'now' => Types::DATETIME_IMMUTABLE],
            );
            foreach ($result->fetchAllAssociative() as $row) {
                $monitorId = ReadRow::int($row['monitor_id'] ?? 0);
                if (isset($slugs[$monitorId])) {
                    $downtime[$monitorId] = new IncidentDowntime($monitorId, $slugs[$monitorId], ReadRow::int($row['n'] ?? 0), ReadRow::int($row['seconds'] ?? 0));
                }
            }
        }

        return $downtime;
    }

    /**
     * The incidents overlapping the window, in the scope (no select yet).
     */
    private function window(MonitorScope $scope, DateTimeImmutable $from, DateTimeImmutable $to, ?bool $open): QueryBuilder
    {
        $builder = $this->entityManager->createQueryBuilder()
            ->from(Incident::class, 'i')
            ->innerJoin('i.monitor', 'm')
            ->innerJoin('m.tenant', 't')
            ->andWhere('i.startedAt < :to')
            ->andWhere('(i.endedAt IS NULL OR i.endedAt >= :from)')
            ->setParameter('from', $from, Types::DATETIME_IMMUTABLE)
            ->setParameter('to', $to, Types::DATETIME_IMMUTABLE);
        if ($open !== null) {
            $builder->andWhere($open ? 'i.endedAt IS NULL' : 'i.endedAt IS NOT NULL');
        }

        return MonitorScopeFilter::apply($builder, $scope);
    }
}
