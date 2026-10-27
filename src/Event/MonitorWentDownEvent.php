<?php

declare(strict_types=1);

namespace Nowo\UptimeMonitorBundle\Event;

use Nowo\UptimeMonitorBundle\Entity\Incident;
use Nowo\UptimeMonitorBundle\Entity\Monitor;

/**
 * A monitor went down (or degraded) and an incident opened (since 1.9). Dispatched once per incident, before the
 * check's flush, so a listener can persist its own records in the same unit of work (e.g. open an issue in the host).
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
}
