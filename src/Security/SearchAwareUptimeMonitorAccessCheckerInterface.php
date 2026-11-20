<?php

declare(strict_types=1);

namespace Nowo\UptimeMonitorBundle\Security;

use Nowo\UptimeMonitorBundle\Event\MonitorSearchAccessEvent;

/**
 * Monitor search permission (since 1.33), separate from the dashboard: whether a viewer may search the monitors of a
 * tenant in the SearchKit palette (group `uptime_monitors`). A host whose monitors live under its own pages (no bundle
 * dashboard) grants search here, or with a {@see MonitorSearchAccessEvent} listener.
 *
 * Without this interface on the configured checker, search is denied unless such a listener grants it. The bundle's
 * own checkers implement it (the configurable one with the dashboard roles, as up to 1.32). Every listed monitor is
 * still checked one by one ({@see MonitorAwareUptimeMonitorAccessCheckerInterface::canViewMonitor()}, else the tenant
 * or dashboard check).
 */
interface SearchAwareUptimeMonitorAccessCheckerInterface extends UptimeMonitorAccessCheckerInterface
{
    /**
     * Whether the user may search the monitors of this tenant.
     */
    public function canSearch(object $user, string $tenantSlug): bool;
}
