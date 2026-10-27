<?php

declare(strict_types=1);

namespace Nowo\UptimeMonitorBundle\Limits;

use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Nowo\UptimeMonitorBundle\Entity\Tenant;
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

    /**
     * Runs a write that adds monitors under the quota without a count-then-insert race (SEC-004 Q3): one transaction
     * holding a PESSIMISTIC_WRITE lock on the tenant row, the remaining quota counted after the lock, the write's
     * changes flushed and committed before the lock is released. Concurrent writers of the same tenant queue up.
     *
     * @template T
     *
     * @param EntityManagerInterface $entityManager Entity manager of the tenant and its monitors
     * @param Tenant $tenant Managed tenant entity (the locked row)
     * @param callable(int|null): T $write Receives the remaining quota (null = no limit)
     *
     * @return T The write's result
     */
    public function withTenantLock(EntityManagerInterface $entityManager, Tenant $tenant, callable $write): mixed
    {
        return $entityManager->wrapInTransaction(function () use ($entityManager, $tenant, $write): mixed {
            $entityManager->lock($tenant, LockMode::PESSIMISTIC_WRITE);

            return $write($this->remaining($tenant->getSlug()));
        });
    }
}
