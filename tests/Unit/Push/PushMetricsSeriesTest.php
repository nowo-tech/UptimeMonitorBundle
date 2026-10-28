<?php

declare(strict_types=1);

namespace Nowo\UptimeMonitorBundle\Tests\Unit\Push;

use DateTimeImmutable;
use Nowo\UptimeMonitorBundle\Entity\CheckResult;
use Nowo\UptimeMonitorBundle\Entity\Monitor;
use Nowo\UptimeMonitorBundle\Entity\Tenant;
use Nowo\UptimeMonitorBundle\Enum\CheckStatus;
use Nowo\UptimeMonitorBundle\Enum\MonitorType;
use Nowo\UptimeMonitorBundle\Push\PushMetricsSeries;
use Nowo\UptimeMonitorBundle\Repository\CheckResultRepository;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;

use function count;

#[CoversClass(PushMetricsSeries::class)]
final class PushMetricsSeriesTest extends TestCase
{
    private Monitor $monitor;

    protected function setUp(): void
    {
        $this->monitor = new Monitor(new Tenant('main', 'Main'), 'web', MonitorType::Push, 'push');
    }

    /**
     * @param list<CheckResult> $results
     */
    private function series(array $results, ?DateTimeImmutable &$since = null): PushMetricsSeries
    {
        $repository = $this->createMock(CheckResultRepository::class);
        $repository->method('findChecksForMonitorSince')->willReturnCallback(static function (Monitor $monitor, DateTimeImmutable $from) use ($results, &$since): array {
            $since = $from;

            return $results;
        });

        return new PushMetricsSeries($repository, new MockClock(new DateTimeImmutable('2026-10-28 12:00:00')));
    }

    /**
     * @param array<string, mixed>|null $metadata
     */
    private function checkResult(string $at, ?array $metadata): CheckResult
    {
        return new CheckResult($this->monitor, CheckStatus::Up, 0, null, null, $metadata, new DateTimeImmutable($at));
    }

    public function testSeriesKeepGapsAndEveryMountSeen(): void
    {
        $since  = null;
        $series = $this->series([
            $this->checkResult('2026-10-28 11:00', ['cpu_pct' => 10.0, 'mem_pct' => 50, 'load5' => 2.0, 'cpu_count' => 4, 'disks' => [['mount' => '/', 'used_pct' => 70.0], 'junk']]),
            $this->checkResult('2026-10-28 11:01', null),
            $this->checkResult('2026-10-28 11:02', ['swap_pct' => 1.5, 'load1' => 1.0, 'disks' => [['mount' => '/var', 'used_pct' => 20.0]]]),
        ], $since)->series($this->monitor, 6);

        self::assertEquals(new DateTimeImmutable('2026-10-28 06:00:00'), $since);
        self::assertSame(6, $series['hours']);
        self::assertSame(['11:00', '11:02'], $series['labels']);
        self::assertSame([10.0, null], $series['cpu']);
        self::assertSame([50.0, null], $series['memory']);
        self::assertSame([null, 1.5], $series['swap']);
        self::assertSame([0.5, 1.0], $series['load']);
        self::assertSame(['/' => [70.0, null], '/var' => [null, 20.0]], $series['disks']);
    }

    public function testAnUnknownRangeFallsBackTo24HoursAndLongRangesAreThinned(): void
    {
        $results = [];
        for ($i = 0; $i < 500; ++$i) {
            $results[] = $this->checkResult('2026-10-27 12:00 +' . $i . ' minutes', ['cpu_pct' => 1.0]);
        }
        $series = $this->series($results)->series($this->monitor, 5);

        self::assertSame(24, $series['hours']);
        self::assertLessThanOrEqual(PushMetricsSeries::MAX_POINTS, count($series['labels']));
        self::assertSame('10-27 12:00', $this->series($results)->series($this->monitor, 168)['labels'][0]);
    }

    public function testGaugesGiveTheLevelOfEachMetricAndDisk(): void
    {
        $series = $this->series([]);
        self::assertNull($series->gauges($this->monitor));

        $this->monitor->recordPush(new DateTimeImmutable(), [
            'cpu_pct'   => 96.0,
            'mem_pct'   => 91.0,
            'swap_pct'  => 1.0,
            'load5'     => 1.0,
            'cpu_count' => 2,
            'uptime_s'  => 3600,
            'disks'     => [
                ['mount' => '/', 'used_pct' => 50.0, 'inodes_pct' => 96.0],
                ['mount' => '/var', 'used_pct' => 90.0],
                ['mount' => '/srv'],
                ['used_pct' => 10.0],
            ],
        ]);
        $gauges = $series->gauges($this->monitor);

        self::assertNotNull($gauges);
        self::assertSame([
            ['key' => 'cpu', 'value' => 96.0, 'level' => 'critical'],
            ['key' => 'memory', 'value' => 91.0, 'level' => 'warn'],
            ['key' => 'swap', 'value' => 1.0, 'level' => 'ok'],
            ['key' => 'load', 'value' => 0.5, 'level' => 'ok'],
        ], $gauges['metrics']);
        self::assertSame([
            ['mount' => '/', 'used' => 50.0, 'inodes' => 96.0, 'level' => 'critical'],
            ['mount' => '/var', 'used' => 90.0, 'inodes' => null, 'level' => 'warn'],
            ['mount' => '/srv', 'used' => null, 'inodes' => null, 'level' => 'ok'],
        ], $gauges['disks']);
        self::assertSame(3600, $gauges['uptime_s']);
        self::assertSame(2, $gauges['cpu_count']);

        $this->monitor->recordPush(new DateTimeImmutable(), ['disks' => 'junk']);
        self::assertSame(['metrics' => [], 'disks' => [], 'uptime_s' => null, 'cpu_count' => null], $series->gauges($this->monitor));
    }
}
