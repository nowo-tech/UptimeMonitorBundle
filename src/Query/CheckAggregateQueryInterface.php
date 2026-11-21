<?php

declare(strict_types=1);

namespace Nowo\UptimeMonitorBundle\Query;

use DateTimeImmutable;
use Nowo\UptimeMonitorBundle\Enum\AggregatePeriod;

/**
 * Read port over the check rollups (since 1.36, REQ-ARCH-001 rule 7): per-monitor totals, pages and counts of the
 * buckets of a window, as read models and numbers, never entities. A host type-hints this interface instead of
 * writing DQL over `CheckAggregate` (e.g. an uptime report).
 *
 * The window is half-open: a bucket is in it when `$from <= periodStart < $to` (dates in the time zone the bundle
 * writes, the PHP default). An empty window (`$from >= $to`) or an empty {@see MonitorScope} answers nothing without a
 * query. The access decision (which tenants a user may read) stays with the caller; the port does not decide access,
 * so a host that denies access per monitor must narrow `monitorIds`. Keep `tenantSlugs` and `monitorIds` to a few
 * thousand entries per call: each is one `IN` list (MySQL, MariaDB and PostgreSQL allow 65,535 placeholders per
 * statement; SQLite before 3.32 only 999). {@see DoctrineCheckAggregateQuery}
 * is the default implementation.
 */
interface CheckAggregateQueryInterface
{
    /**
     * Largest page {@see self::list()} returns.
     */
    public const MAX_LIMIT = 1000;

    /**
     * The buckets of the window summed per monitor, keyed by monitor id (monitors without a bucket are left out). One
     * query, whatever the number of monitors.
     *
     * @param MonitorScope $scope Monitors read
     * @param DateTimeImmutable $from First bucket start included
     * @param DateTimeImmutable $to First bucket start excluded
     * @param AggregatePeriod $period Bucket size summed (a day by default)
     *
     * @return array<int, CheckAggregateTotals>
     */
    public function totalsByMonitor(MonitorScope $scope, DateTimeImmutable $from, DateTimeImmutable $to, AggregatePeriod $period = AggregatePeriod::Day): array;

    /**
     * One page of the buckets of the window, oldest first (then by monitor id and bucket id, so pages are stable).
     * A page can be shorter than `$limit` when rows of look-alike tenants are dropped by the exact PHP re-check: page
     * until an empty page, never until `$offset >= count()`.
     *
     * @param int $limit Page size (1 to {@see self::MAX_LIMIT}; clamped)
     * @param int $offset Rows skipped (0 or more)
     *
     * @return list<CheckAggregateView>
     */
    public function list(MonitorScope $scope, DateTimeImmutable $from, DateTimeImmutable $to, AggregatePeriod $period = AggregatePeriod::Day, int $limit = 100, int $offset = 0): array;

    /**
     * How many buckets of the window {@see self::list()} pages through.
     */
    public function count(MonitorScope $scope, DateTimeImmutable $from, DateTimeImmutable $to, AggregatePeriod $period = AggregatePeriod::Day): int;
}
