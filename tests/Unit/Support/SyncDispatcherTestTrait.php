<?php

declare(strict_types=1);

namespace Nowo\UptimeMonitorBundle\Tests\Unit\Support;

use Doctrine\ORM\EntityManagerInterface;
use Nowo\UptimeMonitorBundle\Check\CheckRunnerInterface;
use Nowo\UptimeMonitorBundle\Repository\CheckAggregateRepository;
use Nowo\UptimeMonitorBundle\Repository\CheckResultRepository;
use Nowo\UptimeMonitorBundle\Repository\IncidentRepository;
use Nowo\UptimeMonitorBundle\Repository\MonitorRepository;
use Nowo\UptimeMonitorBundle\Service\AggregateService;
use Nowo\UptimeMonitorBundle\Service\CheckExecutorService;
use Nowo\UptimeMonitorBundle\Service\CheckLatencyNormalizer;
use Nowo\UptimeMonitorBundle\Service\DashboardSyncDispatcher;
use Nowo\UptimeMonitorBundle\Service\DashboardViewBuilder;
use Nowo\UptimeMonitorBundle\Service\MonitorFactory;
use Nowo\UptimeMonitorBundle\Service\MonitorRetryService;
use Nowo\UptimeMonitorBundle\Service\NotificationService;
use Nowo\UptimeMonitorBundle\Service\StatusTransitionService;
use Nowo\UptimeMonitorBundle\Service\SummaryPayloadBuilder;
use Nowo\UptimeMonitorBundle\Service\TenantDashboardSerializer;
use Nowo\UptimeMonitorBundle\Service\UptimeMetricsService;

/**
 * Helpers for tests that need a real SummaryPayloadBuilder (final class).
 */
trait SyncDispatcherTestTrait
{
    protected function summaryPayloadBuilder(
        ?MonitorRepository $monitorRepository = null,
        ?CheckResultRepository $checkResultRepository = null,
    ): SummaryPayloadBuilder {
        return new SummaryPayloadBuilder(
            $monitorRepository ?? $this->createMock(MonitorRepository::class),
            $checkResultRepository ?? $this->createMock(CheckResultRepository::class),
            new UptimeMetricsService(),
            new TenantDashboardSerializer(),
        );
    }

    protected function pollingSyncDispatcher(): DashboardSyncDispatcher
    {
        return new DashboardSyncDispatcher(
            $this->summaryPayloadBuilder(),
            ['sync' => 'polling'],
            null,
        );
    }

    /**
     * @param iterable<CheckRunnerInterface> $runners
     */
    protected function checkExecutorService(
        iterable $runners = [],
        ?EntityManagerInterface $entityManager = null,
        array $aggregatePeriods = [],
        int $minLatencyMs = 0,
    ): CheckExecutorService {
        $entityManager ??= $this->createMock(EntityManagerInterface::class);

        $aggregateRepository = $this->getMockBuilder(CheckAggregateRepository::class)
            ->onlyMethods(['findOneForBucket'])
            ->disableOriginalConstructor()
            ->getMock();
        $aggregateRepository->method('findOneForBucket')->willReturn(null);

        return new CheckExecutorService(
            $runners,
            $entityManager,
            new AggregateService($entityManager, $aggregateRepository, ['periods' => $aggregatePeriods]),
            new StatusTransitionService(
                $entityManager,
                $this->createMock(IncidentRepository::class),
                new NotificationService([], ['enabled' => false]),
                [],
            ),
            $this->pollingSyncDispatcher(),
            new CheckLatencyNormalizer(['min_latency_ms' => $minLatencyMs]),
            new MonitorRetryService(),
        );
    }

    protected function dashboardViewBuilder(
        ?MonitorRepository $monitorRepository = null,
        ?CheckResultRepository $checkResultRepository = null,
    ): DashboardViewBuilder {
        $aggregateRepository = $this->getMockBuilder(CheckAggregateRepository::class)
            ->disableOriginalConstructor()
            ->getMock();

        return new DashboardViewBuilder(
            $monitorRepository ?? $this->createMock(MonitorRepository::class),
            $checkResultRepository ?? $this->createMock(CheckResultRepository::class),
            $aggregateRepository,
            new UptimeMetricsService(),
            new TenantDashboardSerializer(),
        );
    }

    protected function monitorFactory(?MonitorRepository $monitorRepository = null): MonitorFactory
    {
        return new MonitorFactory($monitorRepository ?? $this->createMock(MonitorRepository::class));
    }
}
