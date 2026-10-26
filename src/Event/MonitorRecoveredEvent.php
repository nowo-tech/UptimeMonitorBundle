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
    public function __construct(
        public Monitor $monitor,
        public Incident $incident,
    ) {
    }
}
