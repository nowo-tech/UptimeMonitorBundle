<?php

declare(strict_types=1);

namespace Nowo\UptimeMonitorBundle\Security;

/**
 * Permissive checker used when security.allow_unauthenticated is true (demo/dev only).
 */
final class AllowAllUptimeMonitorAccessChecker implements UptimeMonitorAccessCheckerInterface
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
}
