<?php

declare(strict_types=1);

namespace Nowo\UptimeMonitorBundle\Query;

use DateTimeImmutable;
use Nowo\UptimeMonitorBundle\Enum\AggregatePeriod;

/**
 * One rollup bucket of a monitor (since 1.36, {@see CheckAggregateQueryInterface::list()}): a read model, never the
 * entity, so nothing stays managed.
 */
final readonly class CheckAggregateView
{
    /**
     * @param int $id Bucket id
     * @param int $monitorId Monitor id
     * @param string $tenantSlug Tenant of the monitor (exact)
     * @param AggregatePeriod $period Bucket size
     * @param DateTimeImmutable $periodStart Start of the bucket
     * @param int $checksTotal Checks in the bucket
     * @param int $checksUp Checks that were up
     * @param int $checksDown Checks that were not up
     * @param float $uptimeRatio Up checks / checks (0 to 1)
     * @param int $latencyAvgMs Mean latency of the bucket, in milliseconds
     */
    public function __construct(
        public int $id,
        public int $monitorId,
        public string $tenantSlug,
        public AggregatePeriod $period,
        public DateTimeImmutable $periodStart,
        public int $checksTotal,
        public int $checksUp,
        public int $checksDown,
        public float $uptimeRatio,
        public int $latencyAvgMs,
    ) {
    }
}
