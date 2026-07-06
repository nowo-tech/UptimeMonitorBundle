<?php

declare(strict_types=1);

namespace Nowo\UptimeMonitorBundle\Tests\Unit\Service;

use DateTimeImmutable;
use Nowo\UptimeMonitorBundle\Entity\CheckAggregate;
use Nowo\UptimeMonitorBundle\Entity\Monitor;
use Nowo\UptimeMonitorBundle\Entity\Tenant;
use Nowo\UptimeMonitorBundle\Enum\AggregatePeriod;
use Nowo\UptimeMonitorBundle\Enum\MonitorType;
use Nowo\UptimeMonitorBundle\Repository\CheckAggregateRepository;
use Nowo\UptimeMonitorBundle\Service\AggregateChartService;
use PHPUnit\Framework\TestCase;

/**
 * @covers \Nowo\UptimeMonitorBundle\Service\AggregateChartService
 */
final class AggregateChartServiceTest extends TestCase
{
    public function testBuildMonitorSeriesMapsAggregates(): void
    {
        $tenant    = new Tenant('main', 'Main');
        $monitor   = new Monitor($tenant, 'API', MonitorType::Https, 'https://example.test');
        $start     = new DateTimeImmutable('2026-05-01');
        $aggregate = new CheckAggregate($monitor, AggregatePeriod::Day, $start);
        $aggregate->applyTotals(10, 9, 120);

        $repo = $this->createMock(CheckAggregateRepository::class);
        $repo->method('findForMonitorInRange')->willReturn([$aggregate]);

        $service = new AggregateChartService($repo);
        $series  = $service->buildMonitorSeries($monitor, AggregatePeriod::Day, 7);

        self::assertSame(['2026-05-01'], $series['labels']);
        self::assertSame([90.0], $series['uptime_percent']);
        self::assertSame([120], $series['latency_avg_ms']);
    }

    public function testBuildTenantOverviewGroupsByMonitor(): void
    {
        $tenant    = new Tenant('main', 'Main');
        $monitor   = new Monitor($tenant, 'API', MonitorType::Https, 'https://example.test');
        $start     = new DateTimeImmutable('2026-05-02');
        $aggregate = new CheckAggregate($monitor, AggregatePeriod::Day, $start);
        $aggregate->applyTotals(5, 4, 80);

        $repo = $this->createMock(CheckAggregateRepository::class);
        $repo->method('findTenantOverview')->willReturn([$aggregate]);

        $service  = new AggregateChartService($repo);
        $overview = $service->buildTenantOverview('main', 14);

        self::assertNotEmpty($overview);
        $first = array_values($overview)[0];
        self::assertSame('API', $first['name']);
        self::assertSame(['2026-05-02'], $first['labels']);
    }

    public function testBuildMonitorSeriesFormatsAllPeriodLabels(): void
    {
        $tenant  = new Tenant('main', 'Main');
        $monitor = new Monitor($tenant, 'API', MonitorType::Https, 'https://example.test');
        $at      = new DateTimeImmutable('2026-05-15 14:30:00');

        $repo = $this->createMock(CheckAggregateRepository::class);
        $repo->method('findForMonitorInRange')->willReturnCallback(
            static function () use ($monitor, $at): array {
                return [
                    new CheckAggregate($monitor, AggregatePeriod::Hour, $at),
                    new CheckAggregate($monitor, AggregatePeriod::Month, $at),
                    new CheckAggregate($monitor, AggregatePeriod::Year, $at),
                ];
            },
        );

        $service = new AggregateChartService($repo);

        self::assertNotEmpty($service->buildMonitorSeries($monitor, AggregatePeriod::Hour, 1)['labels']);
        self::assertNotEmpty($service->buildMonitorSeries($monitor, AggregatePeriod::Month, 1)['labels']);
        self::assertNotEmpty($service->buildMonitorSeries($monitor, AggregatePeriod::Year, 1)['labels']);
    }
}
