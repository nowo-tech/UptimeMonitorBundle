<?php

declare(strict_types=1);

namespace Nowo\UptimeMonitorBundle\Tests\Unit\Entity;

use DateTimeImmutable;
use Nowo\UptimeMonitorBundle\Entity\CheckAggregate;
use Nowo\UptimeMonitorBundle\Entity\Monitor;
use Nowo\UptimeMonitorBundle\Entity\Tenant;
use Nowo\UptimeMonitorBundle\Enum\AggregatePeriod;
use Nowo\UptimeMonitorBundle\Enum\MonitorType;
use PHPUnit\Framework\TestCase;

/**
 * @covers \Nowo\UptimeMonitorBundle\Entity\CheckAggregate
 */
final class CheckAggregateTest extends TestCase
{
    public function testRecordCheckAndApplyTotals(): void
    {
        $monitor   = new Monitor(new Tenant('main', 'Main'), 'API', MonitorType::Https, 'https://x.test');
        $start     = new DateTimeImmutable('2026-05-01');
        $aggregate = new CheckAggregate($monitor, AggregatePeriod::Day, $start);

        $aggregate->recordCheck(true, 100);
        $aggregate->recordCheck(false, 200);

        self::assertSame(2, $aggregate->getChecksTotal());
        self::assertSame(50.0, round($aggregate->getUptimeRatio() * 100, 2));

        $aggregate->applyTotals(10, 8, 90);

        self::assertSame(10, $aggregate->getChecksTotal());
        self::assertSame(90, $aggregate->getLatencyAvgMs());
        self::assertSame(0.8, $aggregate->getUptimeRatio());
        self::assertSame($monitor, $aggregate->getMonitor());
        self::assertSame(AggregatePeriod::Day, $aggregate->getPeriod());
        self::assertSame($start, $aggregate->getPeriodStart());
    }
}
