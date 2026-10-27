<?php

declare(strict_types=1);

namespace Nowo\UptimeMonitorBundle\Limits;

/**
 * Per-tenant limits of the monitor form and the dashboard (`limits.provider`): how many monitors a tenant may hold and
 * how often they may be checked. A host decides them per tenant (plans, instance settings…).
 */
interface TenantLimitsInterface
{
    /**
     * Monitors (groups not counted) the tenant may hold; 0 = no limit.
     */
    public function maxMonitors(string $tenantSlug): int;

    /**
     * Shortest check interval of the tenant's monitors, in seconds.
     */
    public function minIntervalSeconds(string $tenantSlug): int;
}
