<?php

declare(strict_types=1);

namespace Nowo\UptimeMonitorBundle\Security;

/**
 * Global access control for Uptime Monitor dashboard and settings UI.
 */
interface UptimeMonitorAccessCheckerInterface
{
    public function canAccessDashboard(object $user): bool;

    public function canManageMonitors(object $user): bool;

    public function canManageSettings(object $user): bool;
}
