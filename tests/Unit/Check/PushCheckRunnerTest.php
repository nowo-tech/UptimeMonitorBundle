<?php

declare(strict_types=1);

namespace Nowo\UptimeMonitorBundle\Tests\Unit\Check;

use DateTimeImmutable;
use Nowo\UptimeMonitorBundle\Check\PushCheckRunner;
use Nowo\UptimeMonitorBundle\Entity\Monitor;
use Nowo\UptimeMonitorBundle\Entity\Tenant;
use Nowo\UptimeMonitorBundle\Enum\CheckStatus;
use Nowo\UptimeMonitorBundle\Enum\MonitorType;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;

#[CoversClass(PushCheckRunner::class)]
final class PushCheckRunnerTest extends TestCase
{
    private function monitor(): Monitor
    {
        $monitor = new Monitor(new Tenant('main', 'Main'), 'web', MonitorType::Push, 'push');
        $monitor->setIntervalSeconds(60);

        return $monitor;
    }

    public function testSupportsOnlyPushMonitors(): void
    {
        $runner = new PushCheckRunner();

        self::assertTrue($runner->supports($this->monitor()));
        self::assertFalse($runner->supports(new Monitor(new Tenant('main', 'Main'), 'api', MonitorType::Https, 'https://x.test')));
    }

    public function testANewMonitorWaitsThenGoesDownWithoutPushes(): void
    {
        $monitor = $this->monitor();
        $created = $monitor->getCreatedAt();

        $waiting = (new PushCheckRunner(new MockClock($created->modify('+30 seconds'))))->run($monitor);
        self::assertSame(CheckStatus::Unknown, $waiting->status);
        self::assertSame('Waiting for the first push', $waiting->message);

        $silent = (new PushCheckRunner(new MockClock($created->modify('+121 seconds'))))->run($monitor);
        self::assertSame(CheckStatus::Down, $silent->status);
        self::assertSame('No push received yet', $silent->message);
    }

    public function testTheLastPushIsJudgedUntilItIsTooOld(): void
    {
        $monitor = $this->monitor();
        $pushed  = new DateTimeImmutable('2026-10-28 10:00:00');
        $monitor->recordPush($pushed, ['disks' => [['mount' => '/', 'used_pct' => 97.0]]]);

        $fresh = (new PushCheckRunner(new MockClock($pushed->modify('+90 seconds'))))->run($monitor);
        self::assertSame(CheckStatus::Down, $fresh->status);
        self::assertSame('Disk / at 97.0 % (critical ≥ 95 %)', $fresh->message);

        $monitor->setConfig(['push_grace_seconds' => 10]);
        $late = (new PushCheckRunner(new MockClock($pushed->modify('+90 seconds'))))->run($monitor);
        self::assertSame(CheckStatus::Down, $late->status);
        self::assertSame('No push for 90 s (allowed 70 s)', $late->message);
        self::assertNull($late->metadata);
    }

    public function testGraceDefaultsToOneInterval(): void
    {
        $monitor = $this->monitor();
        self::assertSame(60, PushCheckRunner::graceSeconds($monitor));
        $monitor->setConfig(['push_grace_seconds' => -5]);
        self::assertSame(0, PushCheckRunner::graceSeconds($monitor));
    }
}
