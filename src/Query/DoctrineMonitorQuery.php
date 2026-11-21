<?php

declare(strict_types=1);

namespace Nowo\UptimeMonitorBundle\Query;

use DateTimeImmutable;
use DateTimeInterface;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Query\Expr\OrderBy;
use Doctrine\ORM\Query\Parameter;
use Doctrine\ORM\QueryBuilder;
use Nowo\UptimeMonitorBundle\Entity\CheckResult;
use Nowo\UptimeMonitorBundle\Entity\Monitor;
use Nowo\UptimeMonitorBundle\Enum\CheckStatus;
use Nowo\UptimeMonitorBundle\Enum\MonitorType;
use Nowo\UptimeMonitorBundle\Security\SecretRedactor;

use function array_keys;
use function implode;
use function is_bool;
use function is_numeric;
use function is_string;
use function max;
use function min;
use function sprintf;

/**
 * Default {@see MonitorQueryInterface} (since 1.35): DQL over the bundle's own entity manager with scalar results
 * (no entity is loaded or kept, so nothing is managed across requests: FrankenPHP worker mode safe), stateless.
 *
 * The scope of every query is the slug list the caller gives (whole tenants, or single monitors bound to their
 * tenant): an empty scope runs no query. Slugs that are not valid tenant slugs are dropped before the query, so the
 * comparison is exact on any collation, and every listed row is checked against the scope again in PHP.
 */
final readonly class DoctrineMonitorQuery implements MonitorQueryInterface
{
    /** Largest page {@see self::list()} returns. */
    public const MAX_LIMIT = 500;

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
    public function list(MonitorListCriteria $criteria, MonitorListSort $sort = MonitorListSort::Problem, bool $descending = false, int $limit = 50, int $offset = 0, bool $withLatest = true): array
    {
        if ($criteria->isEmpty()) {
            return [];
        }

        $direction = $descending ? 'DESC' : 'ASC';
        $orderBy   = new OrderBy(match ($sort) {
            MonitorListSort::Problem  => 'problem',
            MonitorListSort::Name     => 'm.name',
            MonitorListSort::Type     => 'm.type',
            MonitorListSort::Interval => 'm.intervalSeconds',
            MonitorListSort::Id       => 'm.id',
        }, $direction);
        // Tie-breakers, always ascending, so a page boundary is stable.
        foreach ([MonitorListSort::Name->value => 'm.name', MonitorListSort::Id->value => 'm.id'] as $key => $field) {
            if ($sort->value !== $key) {
                $orderBy->add($field, 'ASC');
            }
        }
        $query = $this->scoped($criteria)
            ->select('m.id AS id', 't.slug AS tenantSlug', 't.name AS tenantName', 'm.name AS name', 'm.type AS type', 'm.target AS target')
            ->addSelect('m.intervalSeconds AS intervalSeconds', 'm.paused AS paused', 'm.lastKnownStatus AS lastKnownStatus', 'IDENTITY(m.parent) AS parentId')
            ->addSelect("CASE WHEN m.paused = true THEN 4 WHEN m.lastKnownStatus = 'down' THEN 0 WHEN m.lastKnownStatus = 'degraded' THEN 1 WHEN m.lastKnownStatus = 'up' THEN 3 ELSE 2 END AS HIDDEN problem")
            ->orderBy($orderBy)
            ->setMaxResults(max(1, min(self::MAX_LIMIT, $limit)))
            ->setFirstResult(max(0, $offset))
            ->getQuery();

        $views = [];
        /** @var list<array<string, mixed>> $rows */
        $rows = $query->getArrayResult();
        foreach ($rows as $row) {
            $view = $this->view($row);
            // Defence in depth: only rows of the scope the caller named, compared byte for byte.
            if ($criteria->covers($view->tenantSlug, $view->id)) {
                $views[] = $view;
            }
        }

        return $withLatest ? $this->withLatest($views) : $views;
    }

    /**
     * {@inheritDoc}
     */
    public function count(MonitorListCriteria $criteria): int
    {
        if ($criteria->isEmpty()) {
            return 0;
        }

        return (int) $this->scoped($criteria)->select('COUNT(m.id)')->getQuery()->getSingleScalarResult();
    }

    /**
     * {@inheritDoc}
     */
    public function countActive(array $tenantSlugs): MonitorCounts
    {
        $slugs = (new MonitorListCriteria($tenantSlugs))->tenantSlugs;
        if ($slugs === []) {
            return new MonitorCounts(0, 0);
        }

        /** @var array<string, mixed> $row */
        $row = $this->entityManager->createQueryBuilder()
            ->select('SUM(CASE WHEN m.type <> :group THEN 1 ELSE 0 END) AS monitors', 'SUM(CASE WHEN m.type = :push THEN 1 ELSE 0 END) AS pushMonitors')
            ->from(Monitor::class, 'm')
            ->innerJoin('m.tenant', 't')
            ->andWhere('t.slug IN (:slugs)')
            ->andWhere('m.paused = false')
            ->setParameter('slugs', $slugs)
            ->setParameter('group', MonitorType::Group->value)
            ->setParameter('push', MonitorType::Push->value)
            ->getQuery()
            ->getSingleResult();

        return new MonitorCounts($this->int($row['monitors'] ?? 0), $this->int($row['pushMonitors'] ?? 0));
    }

    /**
     * {@inheritDoc}
     */
    public function countDown(array $tenantSlugs): int
    {
        return $this->count(new MonitorListCriteria($tenantSlugs, status: MonitorListStatus::Down));
    }

    /**
     * {@inheritDoc}
     */
    public function findInTenant(string $tenantSlug, int $id, bool $withLatest = false): ?MonitorView
    {
        if ($id < 1) {
            return null;
        }

        return $this->list(new MonitorListCriteria([$tenantSlug], monitorIds: [$id], includeGroups: true), limit: 1, withLatest: $withLatest)[0] ?? null;
    }

    /**
     * The monitors of the scope with the filters applied (no select yet).
     */
    private function scoped(MonitorListCriteria $criteria): QueryBuilder
    {
        $scope  = [];
        $params = [];
        if ($criteria->tenantSlugs !== []) {
            $scope[]              = 't.slug IN (:scopeSlugs)';
            $params['scopeSlugs'] = $criteria->tenantSlugs;
        }
        $byTenant = [];
        foreach ($criteria->grantedMonitors as $ref) {
            $byTenant[$ref->tenantSlug][] = $ref->monitorId;
        }
        $index = 0;
        foreach ($byTenant as $slug => $ids) {
            $scope[]                      = sprintf('(t.slug = :grantSlug%1$d AND m.id IN (:grantIds%1$d))', $index);
            $params['grantSlug' . $index] = (string) $slug;
            $params['grantIds' . $index]  = $ids;
            ++$index;
        }
        $where = ['(' . implode(' OR ', $scope) . ')'];

        if ($criteria->monitorIds !== null) {
            $where[]              = 'm.id IN (:monitorIds)';
            $params['monitorIds'] = $criteria->monitorIds;
        }
        if (!$criteria->includeGroups) {
            $where[]             = 'm.type <> :groupType';
            $params['groupType'] = MonitorType::Group->value;
        }
        if ($criteria->type instanceof MonitorType) {
            $where[]        = 'm.type = :type';
            $params['type'] = $criteria->type->value;
        }
        if ($criteria->paused !== null) {
            $where[] = $criteria->paused ? 'm.paused = true' : 'm.paused = false';
        }
        if ($criteria->status === MonitorListStatus::Paused) {
            $where[] = 'm.paused = true';
        } elseif ($criteria->status instanceof MonitorListStatus) {
            $where[]          = 'm.paused = false';
            $where[]          = $criteria->status === MonitorListStatus::Unknown ? '(m.lastKnownStatus IS NULL OR m.lastKnownStatus = :status)' : 'm.lastKnownStatus = :status';
            $params['status'] = $criteria->status->value;
        }

        $parameters = new ArrayCollection();
        foreach ($params as $name => $value) {
            $parameters->add(new Parameter($name, $value));
        }

        return $this->entityManager->createQueryBuilder()
            ->from(Monitor::class, 'm')
            ->innerJoin('m.tenant', 't')
            ->where(implode(' AND ', $where))
            ->setParameters($parameters);
    }

    /**
     * The views with the latest check result of each monitor (one query for the page).
     *
     * @param list<MonitorView> $views
     *
     * @return list<MonitorView>
     */
    private function withLatest(array $views): array
    {
        if ($views === []) {
            return [];
        }
        $ids = [];
        foreach ($views as $view) {
            $ids[$view->id] = true;
        }

        /** @var list<array<string, mixed>> $rows */
        $rows = $this->entityManager->createQueryBuilder()
            ->select('IDENTITY(c.monitor) AS monitorId', 'c.checkedAt AS checkedAt', 'c.status AS status', 'c.latencyMs AS latencyMs', 'c.statusCode AS statusCode')
            ->from(CheckResult::class, 'c')
            ->andWhere('IDENTITY(c.monitor) IN (:ids)')
            ->andWhere('c.checkedAt = (SELECT MAX(c2.checkedAt) FROM ' . CheckResult::class . ' c2 WHERE c2.monitor = c.monitor)')
            ->setParameter('ids', array_keys($ids))
            ->orderBy('c.id', 'DESC')
            ->getQuery()
            ->getArrayResult();

        $latest = [];
        foreach ($rows as $row) {
            $monitorId = $this->int($row['monitorId'] ?? 0);
            $checkedAt = $this->date($row['checkedAt'] ?? null);
            $status    = $this->status($row['status'] ?? null);
            if (isset($latest[$monitorId]) || !$checkedAt instanceof DateTimeImmutable || !$status instanceof CheckStatus) {
                continue;
            }
            $code               = $row['statusCode'] ?? null;
            $latest[$monitorId] = new LatestCheckView($checkedAt, $status, $this->int($row['latencyMs'] ?? 0), is_numeric($code) ? (int) $code : null);
        }

        $result = [];
        foreach ($views as $view) {
            $result[] = $view->withLatest($latest[$view->id] ?? null);
        }

        return $result;
    }

    /**
     * @param array<string, mixed> $row
     */
    private function view(array $row): MonitorView
    {
        $type   = $row['type'] ?? null;
        $parent = $row['parentId'] ?? null;
        $paused = $row['paused'] ?? false;

        return new MonitorView(
            $this->int($row['id'] ?? 0),
            $this->string($row['tenantSlug'] ?? ''),
            $this->string($row['tenantName'] ?? ''),
            $this->string($row['name'] ?? ''),
            $type instanceof MonitorType ? $type : MonitorType::from($this->string($type)),
            SecretRedactor::maskUrl($this->string($row['target'] ?? '')),
            $this->int($row['intervalSeconds'] ?? 0),
            is_bool($paused) ? $paused : (bool) $this->int($paused),
            $this->status($row['lastKnownStatus'] ?? null),
            is_numeric($parent) ? (int) $parent : null,
        );
    }

    private function status(mixed $value): ?CheckStatus
    {
        return $value instanceof CheckStatus ? $value : (is_string($value) ? CheckStatus::tryFrom($value) : null);
    }

    private function date(mixed $value): ?DateTimeImmutable
    {
        return match (true) {
            $value instanceof DateTimeImmutable => $value,
            $value instanceof DateTimeInterface => DateTimeImmutable::createFromInterface($value),
            is_string($value) && $value !== ''  => new DateTimeImmutable($value),
            default                             => null,
        };
    }

    private function int(mixed $value): int
    {
        return is_numeric($value) ? (int) $value : 0;
    }

    private function string(mixed $value): string
    {
        return is_string($value) ? $value : (is_numeric($value) ? (string) $value : '');
    }
}
