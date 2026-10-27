<?php

declare(strict_types=1);

namespace Nowo\UptimeMonitorBundle\Service;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\QueryBuilder;
use InvalidArgumentException;
use Nowo\UptimeMonitorBundle\Doctrine\KeysetBatchDelete;
use Nowo\UptimeMonitorBundle\Entity\CheckAggregate;
use Nowo\UptimeMonitorBundle\Entity\CheckResult;
use Nowo\UptimeMonitorBundle\Entity\Incident;
use Nowo\UptimeMonitorBundle\Entity\Monitor;
use Nowo\UptimeMonitorBundle\Entity\Tenant;
use Nowo\UptimeMonitorBundle\Repository\MonitorRepository;
use Nowo\UptimeMonitorBundle\Repository\TenantRepository;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\Clock\NativeClock;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Throwable;

use function count;
use function sprintf;

/**
 * Removes uptime operational records (checks, aggregates, incidents) and resets monitor status hints.
 *
 * Rows are deleted in keyset batches of retention.purge_batch_size (see {@see KeysetBatchDelete}).
 */
final readonly class UptimeDataClearService
{
    /**
     * @param array<string, mixed> $retentionConfig
     * @param LoggerInterface $logger Logs start, success (counts) and failure (exception class) — REQ-OBS-001
     */
    public function __construct(
        private EntityManagerInterface $entityManager,
        private TenantRepository $tenantRepository,
        private MonitorRepository $monitorRepository,
        #[Autowire('%nowo_uptime_monitor.retention%')]
        private array $retentionConfig = [],
        private ClockInterface $clock = new NativeClock(),
        private LoggerInterface $logger = new NullLogger(),
    ) {
    }

    /**
     * @return array{checks: int, aggregates: int, incidents: int, monitors_reset: int}
     */
    public function clear(?string $tenantSlug = null): array
    {
        if ($tenantSlug !== null && !$this->tenantRepository->findOneBySlug($tenantSlug) instanceof Tenant) {
            throw new InvalidArgumentException(sprintf('Tenant "%s" not found.', $tenantSlug));
        }

        $context = ['bundle' => 'NowoUptimeMonitorBundle', 'tenant' => $tenantSlug ?? '*'];
        $this->logger->info('Uptime data clear started for tenant {tenant}', $context);

        try {
            $incidents  = $this->deleteIncidents($tenantSlug);
            $checks     = $this->deleteCheckResults($tenantSlug);
            $aggregates = $this->deleteAggregates($tenantSlug);
            $reset      = $this->resetMonitors($tenantSlug);

            $this->entityManager->flush();
        } catch (Throwable $e) {
            $this->logger->error('Uptime data clear failed for tenant {tenant}: {error}', $context + ['error' => $e::class]);

            throw $e;
        }

        $result = [
            'checks'         => $checks,
            'aggregates'     => $aggregates,
            'incidents'      => $incidents,
            'monitors_reset' => $reset,
        ];
        $this->logger->info('Uptime data cleared for tenant {tenant}', $context + $result);

        return $result;
    }

    private function deleteCheckResults(?string $tenantSlug): int
    {
        return $this->deleteInBatches(CheckResult::class, $tenantSlug);
    }

    private function deleteAggregates(?string $tenantSlug): int
    {
        return $this->deleteInBatches(CheckAggregate::class, $tenantSlug);
    }

    private function deleteIncidents(?string $tenantSlug): int
    {
        return $this->deleteInBatches(Incident::class, $tenantSlug);
    }

    /**
     * @param class-string $entityClass
     */
    private function deleteInBatches(string $entityClass, ?string $tenantSlug): int
    {
        return KeysetBatchDelete::run(
            $this->entityManager,
            $entityClass,
            function (QueryBuilder $qb, string $alias) use ($tenantSlug): void {
                $this->applyTenantFilter($qb, $alias, $tenantSlug);
            },
            KeysetBatchDelete::batchSizeFrom($this->retentionConfig),
        );
    }

    private function resetMonitors(?string $tenantSlug): int
    {
        $monitors = $tenantSlug !== null
            ? $this->monitorRepository->findByTenantSlug($tenantSlug)
            : $this->monitorRepository->findAll();

        $now = $this->clock->now();

        foreach ($monitors as $monitor) {
            $monitor
                ->setLastKnownStatus(null)
                ->setLastAlertAt(null)
                ->setNextCheckAt($now);
        }

        return count($monitors);
    }

    /**
     * @return list<string>
     */
    public function listTenantSlugs(): array
    {
        $slugs = [];
        foreach ($this->tenantRepository->findAll() as $tenant) {
            $slug = $tenant->getSlug();
            if ($slug !== '') {
                $slugs[] = $slug;
            }
        }

        return $slugs;
    }

    private function applyTenantFilter(QueryBuilder $qb, string $alias, ?string $tenantSlug): void
    {
        if ($tenantSlug === null) {
            return;
        }

        $qb
            ->andWhere(sprintf(
                '%s.monitor IN (SELECT m FROM %s m JOIN m.tenant t WHERE t.slug = :tenantSlug)',
                $alias,
                Monitor::class,
            ))
            ->setParameter('tenantSlug', $tenantSlug);
    }
}
