<?php

declare(strict_types=1);

namespace Nowo\UptimeMonitorBundle\Service;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\QueryBuilder;
use Nowo\UptimeMonitorBundle\Doctrine\KeysetBatchDelete;
use Nowo\UptimeMonitorBundle\Entity\CheckResult;
use Nowo\UptimeMonitorBundle\Entity\Monitor;
use Nowo\UptimeMonitorBundle\Entity\Tenant;
use Nowo\UptimeMonitorBundle\Monitor\TenantSettings;
use Nowo\UptimeMonitorBundle\Repository\TenantRepository;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\Clock\NativeClock;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

use function sprintf;

/**
 * Removes detailed check rows older than {@see Configuration} retention.detail_days.
 *
 * Rows are deleted in keyset batches of retention.purge_batch_size (see {@see KeysetBatchDelete}) so a large
 * check-detail table is never locked by one unbounded DELETE.
 */
final readonly class DetailRetentionService
{
    /**
     * @param array<string, mixed> $retentionConfig
     */
    public function __construct(
        private EntityManagerInterface $entityManager,
        private TenantRepository $tenantRepository,
        #[Autowire('%nowo_uptime_monitor.retention%')]
        private array $retentionConfig,
        private ClockInterface $clock = new NativeClock(),
    ) {
    }

    public function purgeExpiredDetail(): int
    {
        if (!($this->retentionConfig['purge_enabled'] ?? true)) {
            return 0;
        }

        $total = 0;
        foreach ($this->tenantRepository->findAll() as $tenant) {
            $total += $this->purgeExpiredDetailForTenant($tenant->getSlug());
        }

        return $total;
    }

    public function purgeExpiredDetailForTenant(string $tenantSlug): int
    {
        if (!($this->retentionConfig['purge_enabled'] ?? true)) {
            return 0;
        }

        $days = $this->resolveDetailDays($tenantSlug);
        if ($days === null || $days < 1) {
            return 0;
        }

        $cutoff = $this->clock->now()->modify(sprintf('-%d days', $days));

        return KeysetBatchDelete::run(
            $this->entityManager,
            CheckResult::class,
            static function (QueryBuilder $qb, string $alias) use ($cutoff, $tenantSlug): void {
                $qb
                    ->andWhere($alias . '.checkedAt < :cutoff')
                    ->andWhere(sprintf('%s.monitor IN (SELECT m FROM %s m JOIN m.tenant t WHERE t.slug = :slug)', $alias, Monitor::class))
                    ->setParameter('cutoff', $cutoff)
                    ->setParameter('slug', $tenantSlug);
            },
            KeysetBatchDelete::batchSizeFrom($this->retentionConfig),
        );
    }

    private function resolveDetailDays(string $tenantSlug): ?int
    {
        $tenant = $this->tenantRepository->findOneBySlug($tenantSlug);
        if ($tenant instanceof Tenant) {
            $override = TenantSettings::from($tenant)->getDetailRetentionDays();
            if ($override !== null) {
                return $override;
            }
        }

        $days = $this->retentionConfig['detail_days'] ?? null;

        return is_numeric($days) ? (int) $days : null;
    }
}
