<?php

declare(strict_types=1);

namespace Nowo\UptimeMonitorBundle\Tests\Unit\Service;

use Nowo\UptimeMonitorBundle\Entity\Monitor;
use Nowo\UptimeMonitorBundle\Entity\Tenant;
use Nowo\UptimeMonitorBundle\Enum\CheckStatus;
use Nowo\UptimeMonitorBundle\Enum\MonitorType;
use Nowo\UptimeMonitorBundle\Notification\NotificationChannelInterface;
use Nowo\UptimeMonitorBundle\Notification\UptimeAlert;
use Nowo\UptimeMonitorBundle\Service\NotificationService;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * @covers \Nowo\UptimeMonitorBundle\Service\NotificationService
 */
final class NotificationServiceTest extends TestCase
{
    public function testNotifyReturnsZeroWhenDisabled(): void
    {
        $service = new NotificationService([], ['enabled' => false]);

        $alert = new UptimeAlert(
            new Monitor(new Tenant('main', 'Main'), 'API', MonitorType::Https, 'https://x.test'),
            UptimeAlert::TRANSITION_DOWN,
            CheckStatus::Down,
            CheckStatus::Up,
        );

        self::assertSame(0, $service->notify($alert));
    }

    public function testNotifyCountsSuccessfulChannels(): void
    {
        $enabled = $this->createMock(NotificationChannelInterface::class);
        $enabled->method('isEnabled')->willReturn(true);
        $enabled->method('send')->willReturn(true);
        $enabled->method('getName')->willReturn('email');

        $disabled = $this->createMock(NotificationChannelInterface::class);
        $disabled->method('isEnabled')->willReturn(false);

        $service = new NotificationService(
            [$enabled, $disabled],
            ['enabled' => true],
        );

        $alert = new UptimeAlert(
            new Monitor(new Tenant('main', 'Main'), 'API', MonitorType::Https, 'https://x.test'),
            UptimeAlert::TRANSITION_DOWN,
            CheckStatus::Down,
            CheckStatus::Up,
        );

        self::assertSame(1, $service->notify($alert));
    }

    public function testNotifyLogsChannelFailures(): void
    {
        $channel = $this->createMock(NotificationChannelInterface::class);
        $channel->method('isEnabled')->willReturn(true);
        $channel->method('getName')->willReturn('webhook');
        $channel->method('send')->willThrowException(new RuntimeException('boom'));

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())->method('error');

        $service = new NotificationService(
            [$channel],
            ['enabled' => true],
            $logger,
        );

        $alert = new UptimeAlert(
            new Monitor(new Tenant('main', 'Main'), 'API', MonitorType::Https, 'https://x.test'),
            UptimeAlert::TRANSITION_DOWN,
            CheckStatus::Down,
            CheckStatus::Up,
        );

        self::assertSame(0, $service->notify($alert));
    }
}
