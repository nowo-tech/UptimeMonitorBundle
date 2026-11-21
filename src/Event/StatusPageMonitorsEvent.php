<?php

declare(strict_types=1);

namespace Nowo\UptimeMonitorBundle\Event;

use DateTimeImmutable;
use Nowo\UptimeMonitorBundle\Entity\Monitor;
use Nowo\UptimeMonitorBundle\Entity\Tenant;

use function array_flip;
use function is_int;

/**
 * The monitors of a public status page (since 1.12): dispatched before the page renders with the tenant's unpaused
 * monitors, so a host shows only some of them (the overall status follows the ones left). The page keeps only the
 * tenant's unpaused monitors among those set here: a listener narrows the list, it cannot add monitors of another
 * tenant or paused ones.
 *
 * Since 1.36 (REQ-ARCH-001 rules 1 and 4) a listener can work on ids only: {@see self::getTenantSlug()},
 * {@see self::getMonitorIds()}, {@see self::keepMonitorIds()} and {@see self::getOccurredAt()}. The entity accessors
 * (`tenant`, {@see self::getMonitors()}, {@see self::setMonitors()}) keep working; they are deprecated since 1.36 and
 * removed in 2.0 (no runtime deprecation).
 */
final class StatusPageMonitorsEvent
{
    /** Slug of the status page's tenant (since 1.36). */
    public readonly string $tenantSlug;

    /** When the page was requested (since 1.36). */
    public readonly DateTimeImmutable $occurredAt;

    /**
     * Creates the event with the monitors the page would show.
     *
     * @param Tenant $tenant Tenant of the status page (deprecated accessor since 1.36: use `tenantSlug`)
     * @param list<Monitor> $monitors Unpaused monitors of the tenant, by name
     * @param DateTimeImmutable|null $occurredAt Since 1.36: when the page was requested (null = now)
     */
    public function __construct(
        public readonly Tenant $tenant,
        private array $monitors,
        ?DateTimeImmutable $occurredAt = null,
    ) {
        $this->tenantSlug = $tenant->getSlug();
        $this->occurredAt = $occurredAt ?? new DateTimeImmutable();
    }

    /**
     * Slug of the status page's tenant (since 1.36).
     */
    public function getTenantSlug(): string
    {
        return $this->tenantSlug;
    }

    /**
     * When the page was requested (since 1.36).
     */
    public function getOccurredAt(): DateTimeImmutable
    {
        return $this->occurredAt;
    }

    /**
     * Ids of the monitors the page shows, in page order (since 1.36).
     *
     * @return list<int>
     */
    public function getMonitorIds(): array
    {
        $ids = [];
        foreach ($this->monitors as $monitor) {
            $id = $monitor->getId();
            if (is_int($id)) {
                $ids[] = $id;
            }
        }

        return $ids;
    }

    /**
     * Keeps only the monitors with these ids, in page order (since 1.36): the id-only way to narrow the page. Ids the
     * page does not show are ignored (a listener cannot add monitors).
     *
     * @param list<int> $monitorIds Monitor ids to keep
     */
    public function keepMonitorIds(array $monitorIds): void
    {
        $keep = array_flip($monitorIds);
        $kept = [];
        foreach ($this->monitors as $monitor) {
            if (isset($keep[(int) $monitor->getId()])) {
                $kept[] = $monitor;
            }
        }
        // @igor-ignore - Request-scoped event object; not a shared worker service.
        $this->monitors = $kept;
    }

    /**
     * The monitors the page shows.
     *
     * @deprecated since 1.36, removed in 2.0: use {@see self::getMonitorIds()}
     *
     * @return list<Monitor>
     */
    public function getMonitors(): array
    {
        return $this->monitors;
    }

    /**
     * Replaces the monitors the page shows.
     *
     * @deprecated since 1.36, removed in 2.0: use {@see self::keepMonitorIds()}
     *
     * @param list<Monitor> $monitors
     */
    public function setMonitors(array $monitors): void
    {
        // @igor-ignore - Request-scoped event object; not a shared worker service.
        $this->monitors = $monitors;
    }
}
