<?php

declare(strict_types=1);

namespace Nowo\UptimeMonitorBundle\Query;

/**
 * Plan usage of a set of tenants (since 1.35, {@see MonitorQueryInterface::countActive()}): the monitors that are not
 * paused, groups left out, and how many of them are push monitors.
 */
final readonly class MonitorCounts
{
    /**
     * @param int $monitors Monitors that are neither paused nor groups
     * @param int $pushMonitors Of those, the push monitors
     */
    public function __construct(
        public int $monitors,
        public int $pushMonitors,
    ) {
    }
}
