<?php

declare(strict_types=1);

namespace Nowo\UptimeMonitorBundle\Query;

use function intdiv;

/**
 * The rollups of one monitor summed over a window (since 1.36, {@see CheckAggregateQueryInterface::totalsByMonitor()}).
 */
final readonly class CheckAggregateTotals
{
    /**
     * @param int $monitorId Monitor id
     * @param string $tenantSlug Tenant of the monitor (exact)
     * @param int $buckets Buckets summed
     * @param int $checksTotal Checks in all
     * @param int $checksUp Checks that were up
     * @param int $checksDown Checks that were not up
     * @param int $latencyWeightedMs Sum of each bucket's mean latency times its checks (for a weighted mean)
     */
    public function __construct(
        public int $monitorId,
        public string $tenantSlug,
        public int $buckets,
        public int $checksTotal,
        public int $checksUp,
        public int $checksDown,
        public int $latencyWeightedMs,
    ) {
    }

    /**
     * Up checks / checks (0 to 1); null when there was no check.
     */
    public function uptimeRatio(): ?float
    {
        return $this->checksTotal > 0 ? $this->checksUp / $this->checksTotal : null;
    }

    /**
     * Mean latency over the window, weighted by each bucket's checks (milliseconds, rounded down); null without checks.
     */
    public function latencyAvgMs(): ?int
    {
        return $this->checksTotal > 0 ? intdiv($this->latencyWeightedMs, $this->checksTotal) : null;
    }
}
