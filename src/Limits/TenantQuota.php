<?php

declare(strict_types=1);

namespace Nowo\UptimeMonitorBundle\Limits;

use Nowo\UptimeMonitorBundle\Repository\MonitorRepository;

use function max;

/**
 * The monitors a tenant still may add under its {@see TenantLimitsInterface} limit (groups are not counted).
 */
final readonly class TenantQuota
{
    /**
     * Creates the quota from the tenant limits and the monitor repository.
     *
     * @param TenantLimitsInterface $limits Per-tenant limits (`limits.provider`)
     * @param MonitorRepository $monitors Monitor repository
     */
    public function __construct(
        private TenantLimitsInterface $limits,
        private MonitorRepository $monitors,
    ) {
    }

    /**
     * Whether the tenant may add one more monitor.
     */
    public function canAdd(string $tenantSlug): bool
    {
        return $this->remaining($tenantSlug) !== 0;
    }

    /**
     * Monitors the tenant may still add; null when there is no limit.
     */
    public function remaining(string $tenantSlug): ?int
    {
        $max = $this->limits->maxMonitors($tenantSlug);
        if ($max <= 0) {
            return null;
        }

        return max(0, $max - $this->monitors->countChecksByTenantSlug($tenantSlug));
    }

    /**
     * Monitors (groups not counted) the tenant may hold; 0 = no limit.
     */
    public function max(string $tenantSlug): int
    {
        return $this->limits->maxMonitors($tenantSlug);
    }

    /**
     * Shortest check interval of the tenant's monitors, in seconds.
     */
    public function minIntervalSeconds(string $tenantSlug): int
    {
        return $this->limits->minIntervalSeconds($tenantSlug);
    }
}
