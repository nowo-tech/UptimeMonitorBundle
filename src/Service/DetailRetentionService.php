<?php

declare(strict_types=1);

namespace Nowo\UptimeMonitorBundle\Service;

use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Nowo\UptimeMonitorBundle\Entity\CheckResult;
use Nowo\UptimeMonitorBundle\Monitor\TenantSettings;
use Nowo\UptimeMonitorBundle\Repository\TenantRepository;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

use function sprintf;

/**
 * Removes detailed check rows older than {@see Configuration} retention.detail_days.
 */
final class DetailRetentionService
{
    /**
     * @param array<string, mixed> $retentionConfig
     */
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly TenantRepository $tenantRepository,
        #[Autowire('%nowo_uptime_monitor.retention%')]
        private readonly array $retentionConfig,
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

        $cutoff = new DateTimeImmutable(sprintf('-%d days', $days));

        return (int) $this->entityManager->createQueryBuilder()
            ->delete(CheckResult::class, 'c')
            ->andWhere('c.checkedAt < :cutoff')
            ->andWhere('c.monitor IN (SELECT m FROM Nowo\UptimeMonitorBundle\Entity\Monitor m JOIN m.tenant t WHERE t.slug = :slug)')
            ->setParameter('cutoff', $cutoff)
            ->setParameter('slug', $tenantSlug)
            ->getQuery()
            ->execute();
    }

    private function resolveDetailDays(string $tenantSlug): ?int
    {
        $tenant = $this->tenantRepository->findOneBySlug($tenantSlug);
        if ($tenant !== null) {
            $override = TenantSettings::from($tenant)->getDetailRetentionDays();
            if ($override !== null) {
                return $override;
            }
        }

        $days = $this->retentionConfig['detail_days'] ?? null;

        return is_numeric($days) ? (int) $days : null;
    }
}
