<?php

declare(strict_types=1);

namespace Nowo\UptimeMonitorBundle\Security;

/**
 * Permissive checker used when security.allow_unauthenticated is true (demo/dev only).
 */
final class AllowAllUptimeMonitorAccessChecker implements SearchAwareUptimeMonitorAccessCheckerInterface, MonitorIdAwareUptimeMonitorAccessCheckerInterface
{
    /**
     * Always allows the dashboard.
     */
    public function canAccessDashboard(object $user): bool
    {
        return true;
    }

    /**
     * Always allows monitor management.
     */
    public function canManageMonitors(object $user): bool
    {
        return true;
    }

    /**
     * Always allows the settings.
     */
    public function canManageSettings(object $user): bool
    {
        return true;
    }

    /**
     * Whether the user may search the monitors of a tenant (since 1.34): always.
     */
    public function canSearch(object $user, string $tenantSlug): bool
    {
        return true;
    }

    /**
     * Whether the user may read one monitor (since 1.35): always.
     */
    public function canViewMonitorId(object $user, string $tenantSlug, int $monitorId): bool
    {
        return true;
    }
}
