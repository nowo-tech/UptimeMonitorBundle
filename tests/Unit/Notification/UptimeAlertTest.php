<?php

declare(strict_types=1);

namespace Nowo\UptimeMonitorBundle\Tests\Unit\Notification;

use Nowo\UptimeMonitorBundle\Entity\Monitor;
use Nowo\UptimeMonitorBundle\Entity\Tenant;
use Nowo\UptimeMonitorBundle\Enum\CheckStatus;
use Nowo\UptimeMonitorBundle\Enum\MonitorType;
use Nowo\UptimeMonitorBundle\Notification\UptimeAlert;
use PHPUnit\Framework\TestCase;

/**
 * @covers \Nowo\UptimeMonitorBundle\Notification\UptimeAlert
 */
final class UptimeAlertTest extends TestCase
{
    public function testGettersExposeMonitorAndStatuses(): void
    {
        $monitor = new Monitor(new Tenant('main', 'Main'), 'API', MonitorType::Https, 'https://x.test');
        $alert   = new UptimeAlert($monitor, UptimeAlert::TRANSITION_DOWN, CheckStatus::Down, CheckStatus::Up, 'err');

        self::assertSame($monitor, $alert->getMonitor());
        self::assertSame(UptimeAlert::TRANSITION_DOWN, $alert->getTransition());
        self::assertSame(CheckStatus::Down, $alert->getCurrentStatus());
        self::assertSame(CheckStatus::Up, $alert->getPreviousStatus());
        self::assertSame('err', $alert->getMessage());
    }

    public function testDefaultMessageAndSubjects(): void
    {
        $monitor = new Monitor(new Tenant('main', 'Main'), 'API', MonitorType::Https, 'https://x.test');
        $alert   = new UptimeAlert($monitor, UptimeAlert::TRANSITION_DOWN, CheckStatus::Down, CheckStatus::Up);

        self::assertStringContainsString('API', $alert->getMessage());
        self::assertStringContainsString('🔴', $alert->getSubject());
    }

    public function testRecoverySubject(): void
    {
        $monitor = new Monitor(new Tenant('main', 'Main'), 'API', MonitorType::Https, 'https://x.test');
        $alert   = new UptimeAlert(
            $monitor,
            UptimeAlert::TRANSITION_UP,
            CheckStatus::Up,
            CheckStatus::Down,
            'recovered',
        );

        self::assertSame('recovered', $alert->getMessage());
        self::assertStringContainsString('🟢', $alert->getSubject());
    }
}
