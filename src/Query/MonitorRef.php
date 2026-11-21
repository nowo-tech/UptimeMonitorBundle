<?php

declare(strict_types=1);

namespace Nowo\UptimeMonitorBundle\Query;

use InvalidArgumentException;

/**
 * One monitor named by its tenant and id (since 1.35): a single monitor granted outside the tenant scope of a
 * {@see MonitorListCriteria} (e.g. a monitor shared with the viewer). The tenant is part of the grant, so a monitor
 * id never reaches into a tenant the caller did not name.
 */
final readonly class MonitorRef
{
    /**
     * @param string $tenantSlug Tenant the monitor must belong to (compared exactly)
     * @param int $monitorId Monitor id (positive)
     *
     * @throws InvalidArgumentException When the id is not positive
     */
    public function __construct(
        public string $tenantSlug,
        public int $monitorId,
    ) {
        if ($monitorId < 1) {
            throw new InvalidArgumentException('A monitor id is a positive integer.');
        }
    }
}
