<?php

declare(strict_types=1);

namespace Nowo\UptimeMonitorBundle\Tests\Unit\Push;

use Nowo\UptimeMonitorBundle\Entity\Monitor;
use Nowo\UptimeMonitorBundle\Entity\Tenant;
use Nowo\UptimeMonitorBundle\Enum\CheckStatus;
use Nowo\UptimeMonitorBundle\Enum\MonitorType;
use Nowo\UptimeMonitorBundle\Push\PushThresholds;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(PushThresholds::class)]
final class PushThresholdsTest extends TestCase
{
    public function testAHealthySampleIsUp(): void
    {
        $result = (new PushThresholds())->evaluate(['cpu_pct' => 10.0, 'mem_pct' => 40.0, 'disks' => [['mount' => '/', 'used_pct' => 50.0]]]);

        self::assertSame(CheckStatus::Up, $result->status);
        self::assertSame('Push received', $result->message);
        self::assertSame(0, $result->latencyMs);
        self::assertSame(['cpu_pct' => 10.0, 'mem_pct' => 40.0, 'disks' => [['mount' => '/', 'used_pct' => 50.0]]], $result->metadata);
    }

    public function testAWarningIsDegradedAndACriticalIsDownTheWorstWins(): void
    {
        $thresholds = new PushThresholds();

        $warn = $thresholds->evaluate(['mem_pct' => 91.0]);
        self::assertSame(CheckStatus::Degraded, $warn->status);
        self::assertSame('Memory at 91.0 % (warning ≥ 90 %)', $warn->message);

        $down = $thresholds->evaluate(['mem_pct' => 91.0, 'disks' => [['mount' => '/var', 'used_pct' => 96.0, 'inodes_pct' => 10.0], 'junk', ['used_pct' => 99.0]]]);
        self::assertSame(CheckStatus::Down, $down->status);
        self::assertSame('Disk /var at 96.0 % (critical ≥ 95 %)', $down->message);
    }

    public function testLoadIsPerCore(): void
    {
        $thresholds = new PushThresholds();

        self::assertSame(CheckStatus::Up, $thresholds->evaluate(['load5' => 4.0, 'cpu_count' => 4])->status);
        $result = $thresholds->evaluate(['load1' => 8.0, 'cpu_count' => 2]);
        self::assertSame(CheckStatus::Down, $result->status);
        self::assertSame('Load at 4.00 per core (critical ≥ 3.00)', $result->message);
        self::assertSame(CheckStatus::Degraded, $thresholds->evaluate(['load5' => 2.0, 'cpu_count' => 'x'])->status);
    }

    public function testMonitorConfigOverridesAndTurnsOffLimits(): void
    {
        $monitor = new Monitor(new Tenant('main', 'Main'), 'web', MonitorType::Push, 'push');
        $monitor->setConfig(['push_thresholds' => ['cpu' => [null, 50], 'swap' => 'junk', 'memory' => [null, null]]]);
        $thresholds = PushThresholds::of($monitor);

        self::assertSame([null, 50.0], $thresholds->limits['cpu']);
        self::assertSame(PushThresholds::DEFAULTS['swap'], $thresholds->limits['swap']);
        self::assertSame(CheckStatus::Up, $thresholds->evaluate(['mem_pct' => 99.0, 'cpu_pct' => 49.0])->status);
        self::assertSame(CheckStatus::Down, $thresholds->evaluate(['cpu_pct' => 50.0])->status);
        self::assertSame($thresholds->limits, $thresholds->toConfig());
        self::assertSame(PushThresholds::DEFAULTS, PushThresholds::fromConfig(['push_thresholds' => 'junk'])->limits);
    }
}
