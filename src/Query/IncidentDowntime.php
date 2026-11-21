<?php

declare(strict_types=1);

namespace Nowo\UptimeMonitorBundle\Query;

/**
 * The incidents of one monitor that overlap a window, and their downtime inside it (since 1.36,
 * {@see IncidentQueryInterface::downtimeByMonitor()}).
 */
final readonly class IncidentDowntime
{
    /**
     * @param int $monitorId Monitor id
     * @param string $tenantSlug Tenant of the monitor (exact)
     * @param int $incidents Incidents overlapping the window
     * @param int $seconds Their downtime inside the window, in seconds (an open incident runs until "now")
     */
    public function __construct(
        public int $monitorId,
        public string $tenantSlug,
        public int $incidents,
        public int $seconds,
    ) {
    }
}
