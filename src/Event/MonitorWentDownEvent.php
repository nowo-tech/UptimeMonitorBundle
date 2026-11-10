<?php

declare(strict_types=1);

namespace Nowo\UptimeMonitorBundle\Event;

use Nowo\UptimeMonitorBundle\Entity\Incident;
use Nowo\UptimeMonitorBundle\Entity\Monitor;
use Nowo\UptimeMonitorBundle\Model\IncidentSnapshot;

/**
 * A monitor went down (or degraded) and an incident opened (since 1.9). Dispatched once per incident. Since 1.25 it
 * is dispatched after the check's flush (the incident has its id; a failed flush dispatches nothing); before 1.25 it
 * was dispatched before that flush. Entities a listener persists are still flushed right after the dispatch.
 *
 * A host anti-corruption layer reads the scalar getters ({@see self::snapshot()}, since 1.25) instead of the entities.
 */
final readonly class MonitorWentDownEvent
{
    /**
     * Creates the event for a newly opened incident.
     *
     * @param Monitor $monitor Monitor that went down or degraded
     * @param Incident $incident Incident opened for this outage
     * @param string|null $message Failure message of the check that opened the incident
     */
    public function __construct(
        public Monitor $monitor,
        public Incident $incident,
        public ?string $message,
    ) {
    }

    /**
     * Scalar view of the incident and its monitor (since 1.25).
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
     * Incident id (since 1.25; set, as the event is dispatched after the flush).
     */
    public function getIncidentId(): ?int
    {
        return $this->incident->getId();
    }
}
