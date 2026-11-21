<?php

declare(strict_types=1);

namespace Nowo\UptimeMonitorBundle\Security;

use Nowo\UptimeMonitorBundle\Entity\Monitor;

/**
 * The monitor-read decision of a {@see MonitorAwareUptimeMonitorAccessCheckerInterface} (since 1.35): its id form
 * ({@see MonitorIdAwareUptimeMonitorAccessCheckerInterface::canViewMonitorId()}) when the checker implements it, else
 * `canViewMonitor()`. Stateless.
 *
 * @internal
 */
final class MonitorViewDecision
{
    /**
     * Whether the user may read the monitor, already known to belong to its tenant.
     */
    public static function canView(MonitorAwareUptimeMonitorAccessCheckerInterface $checker, object $user, Monitor $monitor): bool
    {
        $id = $monitor->getId();
        if ($checker instanceof MonitorIdAwareUptimeMonitorAccessCheckerInterface && $id !== null) {
            return $checker->canViewMonitorId($user, $monitor->getTenant()->getSlug(), $id);
        }

        return $checker->canViewMonitor($user, $monitor);
    }
}
