<?php

declare(strict_types=1);

namespace Nowo\UptimeMonitorBundle\Event;

use Nowo\UptimeMonitorBundle\Entity\Monitor;
use Nowo\UptimeMonitorBundle\Entity\Tenant;

/**
 * The monitors of a public status page (since 1.12): dispatched before the page renders with the tenant's unpaused
 * monitors, so a host shows only some of them (the overall status follows the ones left).
 */
final class StatusPageMonitorsEvent
{
    /**
     * Creates the event with the monitors the page would show.
     *
     * @param Tenant $tenant Tenant of the status page
     * @param list<Monitor> $monitors Unpaused monitors of the tenant, by name
     */
    public function __construct(
        public readonly Tenant $tenant,
        private array $monitors,
    ) {
    }

    /**
     * The monitors the page shows.
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
     * @param list<Monitor> $monitors
     */
    public function setMonitors(array $monitors): void
    {
        // @igor-ignore - Request-scoped event object; not a shared worker service.
        $this->monitors = $monitors;
    }
}
