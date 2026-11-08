<?php

declare(strict_types=1);

namespace Nowo\UptimeMonitorBundle\Tests\Support;

use Nowo\UptimeMonitorBundle\Entity\Monitor;
use Nowo\UptimeMonitorBundle\Security\MonitorAwareUptimeMonitorAccessCheckerInterface;

use function str_starts_with;

/**
 * A host checker like Beacon's personal tenants (UM-7): `user-*` tenants are closed to the current user, except the
 * monitors whose name starts with "Shared"; every other tenant is open but read-only.
 */
final class SharedMonitorAccessChecker implements MonitorAwareUptimeMonitorAccessCheckerInterface
{
    public function canAccessDashboard(object $user): bool
    {
        return true;
    }

    public function canManageMonitors(object $user): bool
    {
        return false;
    }

    public function canManageSettings(object $user): bool
    {
        return false;
    }

    public function canAccessTenant(object $user, string $tenantSlug): bool
    {
        return !str_starts_with($tenantSlug, 'user-');
    }

    public function canManageTenantMonitors(object $user, string $tenantSlug): bool
    {
        return false;
    }

    public function canManageTenantSettings(object $user, string $tenantSlug): bool
    {
        return false;
    }

    public function canViewMonitor(object $user, Monitor $monitor): bool
    {
        return $this->canAccessTenant($user, $monitor->getTenant()->getSlug()) || str_starts_with($monitor->getName(), 'Shared');
    }
}
