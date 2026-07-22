<?php

declare(strict_types=1);

namespace Nowo\UptimeMonitorBundle\Tests\Unit\Service;

use Nowo\UptimeMonitorBundle\Entity\CheckResult;
use Nowo\UptimeMonitorBundle\Entity\Monitor;
use Nowo\UptimeMonitorBundle\Entity\Tenant;
use Nowo\UptimeMonitorBundle\Enum\CheckStatus;
use Nowo\UptimeMonitorBundle\Enum\MonitorType;
use Nowo\UptimeMonitorBundle\Repository\CheckResultRepository;
use Nowo\UptimeMonitorBundle\Repository\MonitorRepository;
use Nowo\UptimeMonitorBundle\Service\SummaryPayloadBuilder;
use Nowo\UptimeMonitorBundle\Service\TenantDashboardSerializer;
use Nowo\UptimeMonitorBundle\Service\UptimeMetricsService;
use PHPUnit\Framework\TestCase;

/**
 * @covers \Nowo\UptimeMonitorBundle\Service\SummaryPayloadBuilder
 */
final class SummaryPayloadBuilderTest extends TestCase
{
    public function testBuildTenantSummary(): void
    {
        $tenant  = new Tenant('main', 'Main');
        $monitor = new Monitor($tenant, 'API', MonitorType::Https, 'https://example.test');
        $monitor->setIntervalSeconds(60);

        $monitorRepo = $this->createMock(MonitorRepository::class);
        $monitorRepo->method('findByTenantSlug')->willReturn([$monitor]);

        $resultRepo = $this->createMock(CheckResultRepository::class);
        $resultRepo->method('findLatestByMonitorIds')->willReturn([]);

        $builder = new SummaryPayloadBuilder(
            $monitorRepo,
            $resultRepo,
            new UptimeMetricsService(),
            new TenantDashboardSerializer(),
        );
        $summary = $builder->buildTenantSummary('main');

        self::assertSame('main', $summary['tenant']);
        self::assertCount(1, $summary['monitors']);
        self::assertSame('API', $summary['monitors'][0]['name']);
    }

    public function testSerializeMonitorWithLatestResult(): void
    {
        $tenant  = new Tenant('main', 'Main');
        $monitor = new Monitor($tenant, 'API', MonitorType::Https, 'https://example.test');
        $latest  = new CheckResult(
            $monitor,
            CheckStatus::Up,
            42,
            200,
        );

        $resultRepo = $this->createMock(CheckResultRepository::class);
        $resultRepo->method('findRecentForMonitor')->willReturn([$latest]);
        $resultRepo->method('findChecksForMonitorSince')->willReturn([$latest]);

        $builder = new SummaryPayloadBuilder(
            $this->createMock(MonitorRepository::class),
            $resultRepo,
            new UptimeMetricsService(),
            new TenantDashboardSerializer(),
        );

        $item = $builder->buildMonitorSummaryItem($monitor, $latest);

        self::assertSame('up', $item['last_status']);
        self::assertSame(42, $item['last_latency_ms']);
        self::assertNotEmpty($item['history']);
    }

    public function testSerializeMonitorAllowsNullLatest(): void
    {
        $tenant  = new Tenant('main', 'Main');
        $monitor = new Monitor($tenant, 'API', MonitorType::Https, 'https://example.test');

        $builder = new SummaryPayloadBuilder(
            $this->createMock(MonitorRepository::class),
            $this->createMock(CheckResultRepository::class),
            new UptimeMetricsService(),
            new TenantDashboardSerializer(),
        );

        $item = $builder->serializeMonitor($monitor, null, [], null);

        self::assertNull($item['last_status']);
        self::assertNull($item['last_checked_at']);
    }
}
