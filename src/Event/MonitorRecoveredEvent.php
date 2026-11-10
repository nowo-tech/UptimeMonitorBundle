<?php

declare(strict_types=1);

namespace Nowo\UptimeMonitorBundle\Event;

use Nowo\UptimeMonitorBundle\Entity\Incident;
use Nowo\UptimeMonitorBundle\Entity\Monitor;
use Nowo\UptimeMonitorBundle\Model\IncidentSnapshot;

/**
 * A monitor is up again and its open incident resolved (since 1.9). Since 1.25 it is dispatched after the check's
 * flush (a failed flush dispatches nothing); before 1.25 it was dispatched before that flush.
 *
 * A host anti-corruption layer reads the scalar getters ({@see self::snapshot()}, since 1.25) instead of the entities.
 */
final readonly class MonitorRecoveredEvent
{
    /**
     * Creates the event for a resolved incident.
     *
     * @param Monitor $monitor Monitor that is up again
     * @param Incident $incident Incident that was just resolved
     */
    public function __construct(
        public Monitor $monitor,
        public Incident $incident,
    ) {
    }

    /**
     * Scalar view of the resolved incident and its monitor (since 1.25).
     */
    public function snapshot(): IncidentSnapshot
    {
        return IncidentSnapshot::fromIncident($this->incident);
    }

    /**
     * Tenant slug of the monitor (since 1.25).
     */
    public function getTenantSlug(): string
    {
        return $this->monitor->getTenant()->getSlug();
    }

    /**
     * Monitor id (since 1.25).
     */
    public function getMonitorId(): ?int
    {
        return $this->monitor->getId();
    }

    /**
     * Incident id (since 1.25).
     */
    public function getIncidentId(): ?int
    {
        return $this->incident->getId();
    }
}
