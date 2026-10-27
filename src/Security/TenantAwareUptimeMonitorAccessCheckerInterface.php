<?php

declare(strict_types=1);

namespace Nowo\UptimeMonitorBundle\Security;

/**
 * Per-tenant access for multi-tenant hosts (since 1.9): when the configured checker implements this interface, the
 * requests that carry a `{tenantSlug}` (dashboard, monitors, settings, the tenant APIs) are decided for that tenant —
 * e.g. a host that maps one tenant to one of its projects asks its own voter. Requests without a tenant (the tenant
 * index, tenant creation) keep the global methods of {@see UptimeMonitorAccessCheckerInterface}.
 */
interface TenantAwareUptimeMonitorAccessCheckerInterface extends UptimeMonitorAccessCheckerInterface
{
    /**
     * Whether the user may open the dashboard of this tenant.
     */
    public function canAccessTenant(object $user, string $tenantSlug): bool;

    /**
     * Whether the user may create, edit, pause or delete monitors of this tenant.
     */
    public function canManageTenantMonitors(object $user, string $tenantSlug): bool;

    /**
     * Whether the user may change the settings of this tenant.
     */
    public function canManageTenantSettings(object $user, string $tenantSlug): bool;
}
