<?php

declare(strict_types=1);

namespace Nowo\UptimeMonitorBundle\Tests\Unit\Service;

use Nowo\UptimeMonitorBundle\Entity\Monitor;
use Nowo\UptimeMonitorBundle\Entity\Tenant;
use Nowo\UptimeMonitorBundle\Enum\CheckStatus;
use Nowo\UptimeMonitorBundle\Enum\MonitorType;
use Nowo\UptimeMonitorBundle\Notification\NotificationChannelInterface;
use Nowo\UptimeMonitorBundle\Notification\NotificationDeliveryException;
use Nowo\UptimeMonitorBundle\Notification\UptimeAlert;
use Nowo\UptimeMonitorBundle\Service\NotificationService;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

#[CoversClass(NotificationService::class)]
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

    public function testFailureLogNeverContainsRawExceptionMessages(): void
    {
        $channel = $this->createMock(NotificationChannelInterface::class);
        $channel->method('isEnabled')->willReturn(true);
        $channel->method('getName')->willReturn('webhook');
        $channel->method('send')->willThrowException(new RuntimeException('POST https://hooks.example.test/T/SECRET failed'));

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())->method('error')->with(
            self::anything(),
            self::callback(static fn (array $context): bool => $context['error'] === RuntimeException::class
                && $context['channel'] === 'webhook'
                && $context['transition'] === UptimeAlert::TRANSITION_DOWN
                && !str_contains((string) json_encode($context), 'SECRET')),
        );

        (new NotificationService([$channel], ['enabled' => true], $logger))->notify($this->alert());
    }

    public function testFailureLogKeepsSafeDeliveryMessages(): void
    {
        $channel = $this->createMock(NotificationChannelInterface::class);
        $channel->method('isEnabled')->willReturn(true);
        $channel->method('getName')->willReturn('slack');
        $channel->method('send')->willThrowException(new NotificationDeliveryException('slack delivery to https://hooks.slack.com failed: HTTP 404.'));

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())->method('error')->with(
            self::anything(),
            self::callback(static fn (array $context): bool => $context['error'] === 'slack delivery to https://hooks.slack.com failed: HTTP 404.'),
        );

        (new NotificationService([$channel], ['enabled' => true], $logger))->notify($this->alert());
    }

    public function testSuccessIsLoggedAtInfoLevel(): void
    {
        $channel = $this->createMock(NotificationChannelInterface::class);
        $channel->method('isEnabled')->willReturn(true);
        $channel->method('getName')->willReturn('email');
        $channel->method('send')->willReturn(true);

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())->method('info')->with(
            'Uptime notification sent on channel {channel}',
            self::callback(static fn (array $context): bool => $context['channel'] === 'email' && $context['bundle'] === 'NowoUptimeMonitorBundle'),
        );
        $logger->expects(self::never())->method('error');

        self::assertSame(1, (new NotificationService([$channel], ['enabled' => true], $logger))->notify($this->alert()));
    }

    private function alert(): UptimeAlert
    {
        return new UptimeAlert(
            new Monitor(new Tenant('main', 'Main'), 'API', MonitorType::Https, 'https://x.test'),
            UptimeAlert::TRANSITION_DOWN,
            CheckStatus::Down,
            CheckStatus::Up,
        );
    }
}
