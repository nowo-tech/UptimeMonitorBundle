<?php

declare(strict_types=1);

namespace Nowo\UptimeMonitorBundle\Tests\Unit\Service;

use Doctrine\ORM\EntityManagerInterface;
use Nowo\UptimeMonitorBundle\Entity\CheckResult;
use Nowo\UptimeMonitorBundle\Entity\Incident;
use Nowo\UptimeMonitorBundle\Entity\Monitor;
use Nowo\UptimeMonitorBundle\Entity\Tenant;
use Nowo\UptimeMonitorBundle\Enum\CheckStatus;
use Nowo\UptimeMonitorBundle\Enum\MonitorType;
use Nowo\UptimeMonitorBundle\Notification\UptimeAlert;
use Nowo\UptimeMonitorBundle\Repository\IncidentRepository;
use Nowo\UptimeMonitorBundle\Service\NotificationService;
use Nowo\UptimeMonitorBundle\Service\StatusTransitionService;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;

use function count;

#[CoversClass(StatusTransitionService::class)]
final class StatusTransitionResendTest extends TestCase
{
    /** @var list<UptimeAlert> */
    private array $alerts = [];

    private function service(MockClock $clock, int $sent = 1): StatusTransitionService
    {
        $notifications = $this->createStub(NotificationService::class);
        $notifications->method('notify')->willReturnCallback(function (UptimeAlert $alert) use ($sent): int {
            $this->alerts[] = $alert;

            return $sent;
        });
        $incidents = $this->createStub(IncidentRepository::class);
        $incidents->method('findOpenForMonitor')->willReturn(new Incident(new Monitor(new Tenant('t', 'T'), 'x', MonitorType::Http, 'x'), CheckStatus::Down));

        return new StatusTransitionService(
            $this->createStub(EntityManagerInterface::class),
            $incidents,
            $notifications,
            ['enabled' => true, 'cooldown_seconds' => 0],
            null,
            $clock,
        );
    }

    private function alertCount(): int
    {
        return count($this->alerts);
    }

    private function downMonitor(int $resendAfter): Monitor
    {
        $monitor = new Monitor(new Tenant('main', 'Main'), 'API', MonitorType::Https, 'https://x.test');
        $monitor->setConfig(['resend_notification_after_down' => $resendAfter])->setLastKnownStatus(CheckStatus::Down);

        return $monitor;
    }

    public function testDownAlertIsResentEveryNConsecutiveFailures(): void
    {
        $clock   = new MockClock('2026-01-01 00:00:00');
        $service = $this->service($clock);
        $monitor = $this->downMonitor(2);

        $service->handleAfterCheck($monitor, new CheckResult($monitor, CheckStatus::Down, 1));
        self::assertSame(0, $this->alertCount());
        self::assertSame(1, $monitor->getConfig()['down_notify_streak']);

        $clock->sleep(10);
        $service->handleAfterCheck($monitor, new CheckResult($monitor, CheckStatus::Down, 1));
        self::assertSame(1, $this->alertCount());
        self::assertSame('Still down (2 consecutive checks)', $this->alerts[0]->getMessage());
        self::assertSame('2026-01-01 00:00:10', $monitor->getLastAlertAt()?->format('Y-m-d H:i:s'));

        $service->handleAfterCheck($monitor, new CheckResult($monitor, CheckStatus::Down, 1, null, 'timeout'));
        $service->handleAfterCheck($monitor, new CheckResult($monitor, CheckStatus::Down, 1, null, 'timeout'));
        self::assertSame('timeout', $this->alerts[1]->getMessage());

        // Recovery clears the streak.
        $service->handleAfterCheck($monitor, new CheckResult($monitor, CheckStatus::Up, 1));
        self::assertArrayNotHasKey('down_notify_streak', $monitor->getConfig());
    }

    public function testNoResendWithoutThresholdOrWhenNothingWasSent(): void
    {
        $clock = new MockClock('2026-01-01 00:00:00');

        $disabled = $this->downMonitor(0);
        $this->service($clock)->handleAfterCheck($disabled, new CheckResult($disabled, CheckStatus::Down, 1));
        self::assertArrayNotHasKey('down_notify_streak', $disabled->getConfig());

        $unsent = $this->downMonitor(1);
        $this->service($clock, 0)->handleAfterCheck($unsent, new CheckResult($unsent, CheckStatus::Degraded, 1));
        self::assertSame(1, $this->alertCount());
        self::assertNull($unsent->getLastAlertAt());

        $up = $this->downMonitor(1)->setLastKnownStatus(CheckStatus::Up);
        $this->service($clock)->handleAfterCheck($up, new CheckResult($up, CheckStatus::Up, 1));
        self::assertSame(1, $this->alertCount());
    }
}
