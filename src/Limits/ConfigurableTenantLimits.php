<?php

declare(strict_types=1);

namespace Nowo\UptimeMonitorBundle\Limits;

/**
 * Default {@see TenantLimitsInterface}: the same `limits.max_monitors` / `limits.min_interval_seconds` for every tenant.
 */
final readonly class ConfigurableTenantLimits implements TenantLimitsInterface
{
    /**
     * Creates the limits from the bundle configuration.
     *
     * @param int $maxMonitors Monitors per tenant (0 = no limit)
     * @param int $minIntervalSeconds Shortest check interval, in seconds
     */
    public function __construct(
        private int $maxMonitors = 0,
        private int $minIntervalSeconds = 30,
    ) {
    }

    /**
     * Monitors (groups not counted) every tenant may hold; 0 = no limit.
     */
    public function maxMonitors(string $tenantSlug): int
    {
        return $this->maxMonitors;
    }

    /**
     * Shortest check interval of every tenant, in seconds.
     */
    public function minIntervalSeconds(string $tenantSlug): int
    {
        return $this->minIntervalSeconds;
    }
}
