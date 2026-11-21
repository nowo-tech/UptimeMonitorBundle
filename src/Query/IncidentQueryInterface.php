<?php

declare(strict_types=1);

namespace Nowo\UptimeMonitorBundle\Query;

use DateTimeImmutable;

/**
 * Read port over the incidents (since 1.36, REQ-ARCH-001 rule 7): pages, counts and per-monitor downtime of the
 * incidents that overlap a window, as read models and numbers, never entities. A host type-hints this interface
 * instead of writing DQL or SQL over `Incident` (e.g. an uptime report).
 *
 * An incident overlaps the window `[$from, $to)` when it started before `$to` and is open or ended at `$from` or later
 * (dates in the time zone the bundle writes, the PHP default). An empty window (`$from >= $to`) or an empty
 * {@see MonitorScope} answers nothing without a query. The access decision stays with the caller; the port does not
 * decide access, so a host that denies access per monitor must narrow `monitorIds`. Keep `tenantSlugs` and
 * `monitorIds` to a few thousand entries per call (65,535 placeholders per statement on MySQL, MariaDB and
 * PostgreSQL; 999 on SQLite before 3.32; `downtimeByMonitor()` chunks its own id lists by 1,000).
 * {@see DoctrineIncidentQuery} is the default implementation.
 */
interface IncidentQueryInterface
{
    /**
     * Largest page {@see self::list()} returns.
     */
    public const MAX_LIMIT = 2000;

    /**
     * One page of the incidents overlapping the window, newest first (then by id, so pages are stable).
     * A page can be shorter than `$limit` when rows of look-alike tenants are dropped by the exact PHP re-check: page
     * until an empty page, never until `$offset >= count()`.
     *
     * @param bool|null $open Only open (true) or resolved (false) incidents; null for both
     * @param int $limit Page size (1 to {@see self::MAX_LIMIT}; clamped)
     * @param int $offset Rows skipped (0 or more)
     *
     * @return list<IncidentView>
     */
    public function list(MonitorScope $scope, DateTimeImmutable $from, DateTimeImmutable $to, ?bool $open = null, int $limit = 100, int $offset = 0): array;

    /**
     * How many incidents overlapping the window {@see self::list()} pages through.
     *
     * @param bool|null $open Only open (true) or resolved (false) incidents; null for both
     */
    public function count(MonitorScope $scope, DateTimeImmutable $from, DateTimeImmutable $to, ?bool $open = null): int;

    /**
     * Per monitor, the incidents overlapping the window and their downtime inside it (an open incident runs until
     * `$now`), summed in SQL over every incident (no page cap), keyed by monitor id; monitors without an incident are
     * left out. Supported on MySQL, MariaDB, PostgreSQL and SQLite.
     *
     * @return array<int, IncidentDowntime>
     */
    public function downtimeByMonitor(MonitorScope $scope, DateTimeImmutable $from, DateTimeImmutable $to, DateTimeImmutable $now): array;
}
