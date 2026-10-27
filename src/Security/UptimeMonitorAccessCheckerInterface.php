<?php

declare(strict_types=1);

namespace Nowo\UptimeMonitorBundle\Security;

/**
 * Global access control for Uptime Monitor dashboard and settings UI.
 */
interface UptimeMonitorAccessCheckerInterface
{
    /**
     * Whether the user may open the dashboards.
     */
    public function canAccessDashboard(object $user): bool;

    /**
     * Whether the user may create, edit, pause or delete monitors.
     */
    public function canManageMonitors(object $user): bool;

    /**
     * Whether the user may change settings and create tenants.
     */
    public function canManageSettings(object $user): bool;
}
