<?php

declare(strict_types=1);

namespace Nowo\UptimeMonitorBundle\Query;

/**
 * Cross-tenant read port over the monitors (since 1.35): lists, counts and single reads that return read models and
 * numbers, never entities. A host type-hints this interface instead of writing DQL over the bundle entities.
 *
 * Tenant safety: the caller names the scope, always as tenant slugs (whole tenants or single monitors bound to their
 * tenant, {@see MonitorListCriteria}); an empty scope answers nothing. The access decision (which tenants a user may
 * read) stays with the caller. {@see DoctrineMonitorQuery} is the default implementation.
 */
interface MonitorQueryInterface
{
    /**
     * One page of the monitors in scope, sorted; each view carries its latest check result when `$withLatest`.
     *
     * @param MonitorListCriteria $criteria Scope and filters
     * @param MonitorListSort $sort Sort key (ties: name, then id)
     * @param bool $descending Reverse the sort key (the tie-breakers stay ascending)
     * @param int $limit Page size (1 to 500; clamped)
     * @param int $offset Rows skipped (0 or more)
     * @param bool $withLatest Whether each view carries its latest check result (one extra query per page)
     *
     * @return list<MonitorView>
     */
    public function list(MonitorListCriteria $criteria, MonitorListSort $sort = MonitorListSort::Problem, bool $descending = false, int $limit = 50, int $offset = 0, bool $withLatest = true): array;

    /**
     * How many monitors the criteria match (the total of {@see self::list()}).
     */
    public function count(MonitorListCriteria $criteria): int;

    /**
     * Plan usage of these tenants: monitors that are not paused (groups left out), and of those the push monitors. One
     * query; an empty or invalid slug list counts nothing.
     *
     * @param list<string> $tenantSlugs Tenant slugs
     */
    public function countActive(array $tenantSlugs): MonitorCounts;

    /**
     * How many monitors of these tenants are down (not paused, groups left out). An empty slug list counts nothing.
     *
     * @param list<string> $tenantSlugs Tenant slugs
     */
    public function countDown(array $tenantSlugs): int;

    /**
     * The monitor with this id when it belongs to this tenant (exact slug), else null.
     */
    public function findInTenant(string $tenantSlug, int $id, bool $withLatest = false): ?MonitorView;
}
