<?php

declare(strict_types=1);

namespace Nowo\UptimeMonitorBundle\Service;

use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\QueryBuilder;
use InvalidArgumentException;
use Nowo\UptimeMonitorBundle\Entity\CheckAggregate;
use Nowo\UptimeMonitorBundle\Entity\CheckResult;
use Nowo\UptimeMonitorBundle\Entity\Incident;
use Nowo\UptimeMonitorBundle\Entity\Monitor;
use Nowo\UptimeMonitorBundle\Repository\MonitorRepository;
use Nowo\UptimeMonitorBundle\Repository\TenantRepository;

use function count;
use function sprintf;

/**
 * Removes uptime operational records (checks, aggregates, incidents) and resets monitor status hints.
 */
final class UptimeDataClearService
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly TenantRepository $tenantRepository,
        private readonly MonitorRepository $monitorRepository,
    ) {
    }

    /**
     * @return array{checks: int, aggregates: int, incidents: int, monitors_reset: int}
     */
    public function clear(?string $tenantSlug = null): array
    {
        if ($tenantSlug !== null && $this->tenantRepository->findOneBySlug($tenantSlug) === null) {
            throw new InvalidArgumentException(sprintf('Tenant "%s" not found.', $tenantSlug));
        }

        $incidents  = $this->deleteIncidents($tenantSlug);
        $checks     = $this->deleteCheckResults($tenantSlug);
        $aggregates = $this->deleteAggregates($tenantSlug);
        $reset      = $this->resetMonitors($tenantSlug);

        $this->entityManager->flush();

        return [
            'checks'         => $checks,
            'aggregates'     => $aggregates,
            'incidents'      => $incidents,
            'monitors_reset' => $reset,
        ];
    }

    private function deleteCheckResults(?string $tenantSlug): int
    {
        $qb = $this->entityManager->createQueryBuilder()
            ->delete(CheckResult::class, 'c');

        $this->applyTenantFilter($qb, 'c', $tenantSlug);

        return (int) $qb->getQuery()->execute();
    }

    private function deleteAggregates(?string $tenantSlug): int
    {
        $qb = $this->entityManager->createQueryBuilder()
            ->delete(CheckAggregate::class, 'a');

        $this->applyTenantFilter($qb, 'a', $tenantSlug);

        return (int) $qb->getQuery()->execute();
    }

    private function deleteIncidents(?string $tenantSlug): int
    {
        $qb = $this->entityManager->createQueryBuilder()
            ->delete(Incident::class, 'i');

        $this->applyTenantFilter($qb, 'i', $tenantSlug);

        return (int) $qb->getQuery()->execute();
    }

    private function resetMonitors(?string $tenantSlug): int
    {
        $monitors = $tenantSlug !== null
            ? $this->monitorRepository->findByTenantSlug($tenantSlug)
            : $this->monitorRepository->findAll();

        $now = new DateTimeImmutable();

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

    /**
     * @param QueryBuilder<object> $qb
     */
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
