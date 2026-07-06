<?php

declare(strict_types=1);

namespace Nowo\UptimeMonitorBundle\Tests\Unit\Support;

use Nowo\UptimeMonitorBundle\Repository\CheckResultRepository;
use Nowo\UptimeMonitorBundle\Repository\MonitorRepository;
use Nowo\UptimeMonitorBundle\Service\DashboardSyncDispatcher;
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
}
