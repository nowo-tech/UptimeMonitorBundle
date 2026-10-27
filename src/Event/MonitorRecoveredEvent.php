<?php

declare(strict_types=1);

namespace Nowo\UptimeMonitorBundle\Event;

use Nowo\UptimeMonitorBundle\Entity\Incident;
use Nowo\UptimeMonitorBundle\Entity\Monitor;

/**
 * A monitor is up again and its open incident resolved (since 1.9). Dispatched before the check's flush.
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
}
