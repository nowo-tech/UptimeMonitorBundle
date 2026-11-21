<?php

declare(strict_types=1);

namespace Nowo\UptimeMonitorBundle\Repository;

use Nowo\UptimeMonitorBundle\Entity\Monitor;
use Nowo\UptimeMonitorBundle\Query\MonitorQueryInterface;

/**
 * Monitor lookups host code type-hints (since 1.35); {@see MonitorRepository} implements it. A new interface rather
 * than a method added to an existing one, so no host implementation breaks. Hosts that want plain values instead of
 * the entity use {@see MonitorQueryInterface::findInTenant()}.
 */
interface MonitorRepositoryInterface
{
    /**
     * The monitor with this id when it belongs to the tenant with this slug (compared exactly), else null.
     */
    public function findInTenant(string $tenantSlug, int $id): ?Monitor;
}
