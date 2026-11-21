<?php

declare(strict_types=1);

namespace Nowo\UptimeMonitorBundle\Security;

/**
 * Monitor-level read access by id (since 1.35): the id form of
 * {@see MonitorAwareUptimeMonitorAccessCheckerInterface::canViewMonitor()}, so a host decides without a bundle entity
 * (DDD hosts). A separate interface, so existing checkers keep compiling; the bundle's own checkers implement it.
 *
 * When the configured checker implements this interface and {@see MonitorAwareUptimeMonitorAccessCheckerInterface},
 * the bundle asks this method instead of `canViewMonitor()` for the reads of one monitor (its page, history and
 * aggregates APIs, the children of a group, the search results). The bundle has already checked that the monitor exists
 * and belongs to `$tenantSlug` before it asks. A checker that implements only this interface changes nothing on the
 * bundle's routes (they keep the tenant or global checks); host code may still call it.
 */
interface MonitorIdAwareUptimeMonitorAccessCheckerInterface extends UptimeMonitorAccessCheckerInterface
{
    /**
     * Whether the user may read the monitor `$monitorId` of the tenant `$tenantSlug` (its page, history and aggregates).
     */
    public function canViewMonitorId(object $user, string $tenantSlug, int $monitorId): bool;
}
