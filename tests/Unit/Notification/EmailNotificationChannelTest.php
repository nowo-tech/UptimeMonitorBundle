<?php

declare(strict_types=1);

namespace Nowo\UptimeMonitorBundle\Tests\Unit\Notification;

use Nowo\UptimeMonitorBundle\Entity\Monitor;
use Nowo\UptimeMonitorBundle\Entity\Tenant;
use Nowo\UptimeMonitorBundle\Enum\CheckStatus;
use Nowo\UptimeMonitorBundle\Enum\MonitorType;
use Nowo\UptimeMonitorBundle\Notification\Channel\EmailNotificationChannel;
use Nowo\UptimeMonitorBundle\Notification\UptimeAlert;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Mailer\MailerInterface;

/**
 * @covers \Nowo\UptimeMonitorBundle\Notification\Channel\EmailNotificationChannel
 */
final class EmailNotificationChannelTest extends TestCase
{
    public function testIsEnabledRequiresMailerAndConfig(): void
    {
        $channel = new EmailNotificationChannel(['enabled' => false, 'email' => ['enabled' => true]]);
        self::assertFalse($channel->isEnabled());

        $channel = new EmailNotificationChannel(['enabled' => true, 'email' => ['enabled' => false]]);
        self::assertFalse($channel->isEnabled());

        $channel = new EmailNotificationChannel(['enabled' => true, 'email' => ['enabled' => true]]);
        self::assertFalse($channel->isEnabled());

        $mailer  = $this->createMock(MailerInterface::class);
        $channel = new EmailNotificationChannel(
            ['enabled' => true, 'email' => ['enabled' => true]],
            $mailer,
        );
        self::assertTrue($channel->isEnabled());
    }

    public function testSendReturnsFalseWhenMisconfigured(): void
    {
        $mailer = $this->createMock(MailerInterface::class);
        $mailer->expects(self::never())->method('send');
        $channel = new EmailNotificationChannel(
            ['enabled' => true, 'email' => ['enabled' => true, 'from' => '', 'to' => []]],
            $mailer,
        );

        $alert = new UptimeAlert(
            new Monitor(new Tenant('main', 'Main'), 'API', MonitorType::Https, 'https://x.test'),
            UptimeAlert::TRANSITION_DOWN,
            CheckStatus::Down,
            CheckStatus::Up,
        );

        self::assertFalse($channel->send($alert));
    }

    public function testSendDispatchesEmail(): void
    {
        $mailer = $this->createMock(MailerInterface::class);
        $mailer->expects(self::once())->method('send');

        $channel = new EmailNotificationChannel([
            'enabled' => true,
            'email'   => [
                'enabled' => true,
                'from'    => 'alerts@example.test',
                'to'      => ['ops@example.test'],
            ],
        ], $mailer);

        $alert = new UptimeAlert(
            new Monitor(new Tenant('main', 'Main'), 'API', MonitorType::Https, 'https://x.test'),
            UptimeAlert::TRANSITION_DOWN,
            CheckStatus::Down,
            CheckStatus::Up,
            'down',
        );

        self::assertTrue($channel->send($alert));
        self::assertSame('email', $channel->getName());
    }
}
