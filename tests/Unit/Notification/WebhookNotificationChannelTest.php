<?php

declare(strict_types=1);

namespace Nowo\UptimeMonitorBundle\Tests\Unit\Notification;

use Nowo\UptimeMonitorBundle\Entity\Monitor;
use Nowo\UptimeMonitorBundle\Entity\Tenant;
use Nowo\UptimeMonitorBundle\Enum\CheckStatus;
use Nowo\UptimeMonitorBundle\Enum\MonitorType;
use Nowo\UptimeMonitorBundle\Notification\Channel\WebhookNotificationChannel;
use Nowo\UptimeMonitorBundle\Notification\UptimeAlert;
use Nowo\UptimeMonitorBundle\Tests\Unit\Support\EntityIdTrait;
use PHPUnit\Framework\TestCase;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

/**
 * @covers \Nowo\UptimeMonitorBundle\Notification\Channel\WebhookNotificationChannel
 */
final class WebhookNotificationChannelTest extends TestCase
{
    use EntityIdTrait;

    public function testGenericWebhookSend(): void
    {
        $response = $this->createMock(ResponseInterface::class);
        $client   = $this->createMock(HttpClientInterface::class);
        $client->expects(self::once())->method('request')->willReturn($response);

        $channel = new WebhookNotificationChannel([
            'enabled' => true,
            'webhook' => ['enabled' => true, 'url' => 'https://hooks.example.test/uptime'],
        ], $client);

        $monitor = new Monitor(new Tenant('main', 'Main'), 'API', MonitorType::Https, 'https://x.test');
        $this->setEntityId($monitor, 5);

        $alert = new UptimeAlert($monitor, UptimeAlert::TRANSITION_DOWN, CheckStatus::Down, CheckStatus::Up);

        self::assertTrue($channel->isEnabled());
        self::assertTrue($channel->send($alert));
        self::assertSame('webhook', $channel->getName());
    }

    public function testSlackWebhookUsesTextPayload(): void
    {
        $response = $this->createMock(ResponseInterface::class);
        $client   = $this->createMock(HttpClientInterface::class);
        $client->expects(self::once())
            ->method('request')
            ->with(
                'POST',
                'https://hooks.slack.test/xxx',
                self::callback(
                    static fn (array $options): bool => isset($options['json']['text']),
                ),
            )
            ->willReturn($response);

        $channel = new WebhookNotificationChannel([
            'enabled' => true,
            'slack'   => ['enabled' => true, 'webhook_url' => 'https://hooks.slack.test/xxx'],
        ], $client, WebhookNotificationChannel::CHANNEL_SLACK);

        $alert = new UptimeAlert(
            new Monitor(new Tenant('main', 'Main'), 'API', MonitorType::Https, 'https://x.test'),
            UptimeAlert::TRANSITION_UP,
            CheckStatus::Up,
            CheckStatus::Down,
        );

        self::assertTrue($channel->send($alert));
        self::assertSame('slack', $channel->getName());
    }

    public function testSendReturnsFalseWhenDisabled(): void
    {
        $client = $this->createMock(HttpClientInterface::class);
        $client->expects(self::never())->method('request');

        $channel = new WebhookNotificationChannel(['enabled' => false], $client);

        $alert = new UptimeAlert(
            new Monitor(new Tenant('main', 'Main'), 'API', MonitorType::Https, 'https://x.test'),
            UptimeAlert::TRANSITION_DOWN,
            CheckStatus::Down,
            CheckStatus::Up,
        );

        self::assertFalse($channel->send($alert));
    }
}
