<?php

declare(strict_types=1);

namespace Nowo\UptimeMonitorBundle\Tests\Unit\Service;

use DateTimeImmutable;
use Nowo\UptimeMonitorBundle\Entity\Monitor;
use Nowo\UptimeMonitorBundle\Entity\Tenant;
use Nowo\UptimeMonitorBundle\Enum\CheckStatus;
use Nowo\UptimeMonitorBundle\Enum\MonitorType;
use Nowo\UptimeMonitorBundle\Model\CheckResultDto;
use Nowo\UptimeMonitorBundle\Service\MonitorRetryService;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(MonitorRetryService::class)]
final class MonitorRetrySchedulingTest extends TestCase
{
    private function monitor(int $retries): Monitor
    {
        return (new Monitor(new Tenant('main', 'Main'), 'API', MonitorType::Https, 'https://x.test'))
            ->setRetries($retries)
            ->setIntervalSeconds(300)
            ->setRetryIntervalSeconds(45);
    }

    public function testFailuresWithinTheRetryBudgetUseTheRetryInterval(): void
    {
        $service = new MonitorRetryService();
        $from    = new DateTimeImmutable('2026-01-01 00:00:00');
        $down    = new CheckResultDto(CheckStatus::Down, 1);
        $monitor = $this->monitor(2);

        self::assertTrue($service->shouldUseRetryInterval($monitor, $down));
        $service->scheduleAfterCheck($monitor, $from, $down);
        self::assertSame('2026-01-01 00:00:45', $monitor->getNextCheckAt()?->format('Y-m-d H:i:s'));

        $service->normalizeResult($monitor, $down);
        $service->normalizeResult($monitor, $down);
        self::assertFalse($service->shouldUseRetryInterval($monitor, $down), 'budget exhausted');
        $service->scheduleAfterCheck($monitor, $from, $down);
        self::assertSame('2026-01-01 00:05:00', $monitor->getNextCheckAt()?->format('Y-m-d H:i:s'));
    }

    public function testSuccessOrNoRetriesUseTheNormalInterval(): void
    {
        $service = new MonitorRetryService();

        self::assertFalse($service->shouldUseRetryInterval($this->monitor(0), new CheckResultDto(CheckStatus::Down, 1)));
        self::assertFalse($service->shouldUseRetryInterval($this->monitor(3), new CheckResultDto(CheckStatus::Up, 1)));
    }

    public function testSuccessResetsTheFailureStreak(): void
    {
        $service = new MonitorRetryService();
        $monitor = $this->monitor(3);

        $service->normalizeResult($monitor, new CheckResultDto(CheckStatus::Down, 1));
        self::assertSame(1, $monitor->getConfig()['failure_streak']);

        $up = $service->normalizeResult($monitor, new CheckResultDto(CheckStatus::Up, 1));
        self::assertSame(CheckStatus::Up, $up->status);
        self::assertSame(0, $monitor->getConfig()['failure_streak']);
    }

    public function testUpsideDownTurnsReachableIntoDown(): void
    {
        $monitor = $this->monitor(0)->setConfig(['upside_down' => true]);

        $result = (new MonitorRetryService())->normalizeResult($monitor, new CheckResultDto(CheckStatus::Up, 3, 200));

        self::assertSame(CheckStatus::Down, $result->status);
        self::assertSame('Upside-down: reachable', $result->message);
    }
}
