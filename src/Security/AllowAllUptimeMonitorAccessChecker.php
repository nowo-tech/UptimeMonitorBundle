<?php

declare(strict_types=1);

namespace Nowo\UptimeMonitorBundle\Security;

/**
 * Permissive checker used when security.allow_unauthenticated is true (demo/dev only).
 */
final class AllowAllUptimeMonitorAccessChecker implements UptimeMonitorAccessCheckerInterface
{
    public function canAccessDashboard(object $user): bool
    {
        return true;
    }

    public function canManageMonitors(object $user): bool
    {
        return true;
    }

    public function canManageSettings(object $user): bool
    {
        return true;
    }
}
