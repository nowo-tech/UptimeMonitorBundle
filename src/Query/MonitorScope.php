<?php

declare(strict_types=1);

namespace Nowo\UptimeMonitorBundle\Query;

use function array_keys;

/**
 * Which monitors a report read covers (since 1.36, {@see CheckAggregateQueryInterface}, {@see IncidentQueryInterface}).
 *
 * The scope is explicit and nothing else widens it: every monitor of the tenants in `$tenantSlugs`, plus the single
 * monitors in `$grantedMonitors`, each bound to its own tenant. `$monitorIds` only narrows that scope (null = no
 * restriction). An empty scope reads nothing (no query runs). Slugs that are not valid tenant slugs (lowercase letters,
 * digits and hyphens) and ids below 1 are dropped; every row read is checked against the scope again in PHP with `===`,
 * so a look-alike tenant that a case- or accent-insensitive collation matches is never read. Group monitors are
 * included (they have rollups and incidents of their own).
 */
final readonly class MonitorScope
{
    /** @var list<string> */
    public array $tenantSlugs;

    /** @var list<MonitorRef> */
    public array $grantedMonitors;

    /** @var list<int>|null */
    public ?array $monitorIds;

    /** @var array<string, true> In-scope tenant slugs, for the exact re-check */
    private array $tenantSet;

    /** @var array<string, true> In-scope grants as `id@slug`, for the exact re-check */
    private array $grantSet;

    /** @var array<int, true>|null The id filter as a set */
    private ?array $idSet;

    /**
     * @param list<string> $tenantSlugs Tenants whose monitors are in scope (invalid slugs are dropped)
     * @param list<MonitorRef> $grantedMonitors Single monitors in scope, each with its tenant (invalid slugs are dropped)
     * @param list<int>|null $monitorIds Only these monitor ids (null = no restriction; an empty list matches nothing)
     */
    public function __construct(array $tenantSlugs = [], array $grantedMonitors = [], ?array $monitorIds = null)
    {
        $criteria              = new MonitorListCriteria($tenantSlugs, $grantedMonitors);
        $this->tenantSlugs     = $criteria->tenantSlugs;
        $this->grantedMonitors = $criteria->grantedMonitors;
        $tenants               = $grants = [];
        foreach ($this->tenantSlugs as $slug) {
            $tenants[$slug] = true;
        }
        foreach ($this->grantedMonitors as $ref) {
            $grants[$ref->monitorId . '@' . $ref->tenantSlug] = true;
        }
        $this->tenantSet = $tenants;
        $this->grantSet  = $grants;

        if ($monitorIds === null) {
            $this->monitorIds = $this->idSet = null;

            return;
        }
        $ids = [];
        foreach ($monitorIds as $id) {
            if ($id > 0) {
                $ids[$id] = true;
            }
        }
        $this->idSet      = $ids;
        $this->monitorIds = array_keys($ids);
    }

    /**
     * The monitors of these tenants (optionally only these ids).
     *
     * @param list<string> $tenantSlugs Tenant slugs
     * @param list<int>|null $monitorIds Only these monitor ids (null = all)
     */
    public static function tenants(array $tenantSlugs, ?array $monitorIds = null): self
    {
        return new self($tenantSlugs, [], $monitorIds);
    }

    /**
     * Whether nothing is in scope (the query answers nothing without reading).
     */
    public function isEmpty(): bool
    {
        return ($this->tenantSlugs === [] && $this->grantedMonitors === []) || $this->monitorIds === [];
    }

    /**
     * Whether a monitor of that tenant and id is in scope (exact comparison, `$monitorIds` included).
     */
    public function covers(string $tenantSlug, int $monitorId): bool
    {
        if ($this->idSet !== null && !isset($this->idSet[$monitorId])) {
            return false;
        }

        // Array keys compare byte for byte.
        return isset($this->tenantSet[$tenantSlug]) || isset($this->grantSet[$monitorId . '@' . $tenantSlug]);
    }
}
