<?php

declare(strict_types=1);

namespace Nowo\UptimeMonitorBundle\Tests\Unit\Notification;

use Nowo\UptimeMonitorBundle\Entity\Monitor;
use Nowo\UptimeMonitorBundle\Entity\Tenant;
use Nowo\UptimeMonitorBundle\Enum\CheckStatus;
use Nowo\UptimeMonitorBundle\Enum\MonitorType;
use Nowo\UptimeMonitorBundle\Notification\Channel\WebhookNotificationChannel;
use Nowo\UptimeMonitorBundle\Notification\NotificationDeliveryException;
use Nowo\UptimeMonitorBundle\Notification\UptimeAlert;
use Nowo\UptimeMonitorBundle\Tests\Unit\Support\EntityIdTrait;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

#[CoversClass(WebhookNotificationChannel::class)]
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

    public function testDeliveryUsesConfiguredTimeoutAsIdleAndTotalDeadline(): void
    {
        $captured = [];
        $client   = new MockHttpClient(static function (string $method, string $url, array $options) use (&$captured): MockResponse {
            $captured = $options;

            return new MockResponse('', ['http_code' => 204]);
        });

        $channel = new WebhookNotificationChannel([
            'enabled'      => true,
            'http_timeout' => 4.0,
            'webhook'      => ['enabled' => true, 'url' => 'https://hooks.example.test/uptime'],
        ], $client);

        self::assertTrue($channel->send($this->alert()));
        self::assertSame(4.0, $captured['timeout']);
        self::assertSame(4.0, $captured['max_duration']);
    }

    public function testDefaultTimeoutIsTenSeconds(): void
    {
        $captured = [];
        $client   = new MockHttpClient(static function (string $method, string $url, array $options) use (&$captured): MockResponse {
            $captured = $options;

            return new MockResponse('');
        });

        $channel = new WebhookNotificationChannel([
            'enabled' => true,
            'webhook' => ['enabled' => true, 'url' => 'https://hooks.example.test/uptime'],
        ], $client);

        $channel->send($this->alert());
        self::assertSame(WebhookNotificationChannel::DEFAULT_TIMEOUT_SECONDS, $captured['max_duration']);
    }

    public function testTimeoutRaisesARedactedDeliveryException(): void
    {
        // A timed-out response: MockHttpClient reports it as a TransportException mentioning the full URL.
        $client = new MockHttpClient(new MockResponse([''], ['error' => 'Idle timeout reached for "https://hooks.slack.test/services/T000/B000/SECRET".']));

        $channel = new WebhookNotificationChannel([
            'enabled' => true,
            'slack'   => ['enabled' => true, 'webhook_url' => 'https://hooks.slack.test/services/T000/B000/SECRET'],
        ], $client, WebhookNotificationChannel::CHANNEL_SLACK);

        try {
            $channel->send($this->alert());
            self::fail('Expected a NotificationDeliveryException.');
        } catch (NotificationDeliveryException $e) {
            self::assertStringContainsString('slack delivery to https://hooks.slack.test failed', $e->getMessage());
            self::assertStringNotContainsString('SECRET', $e->getMessage());
            self::assertNull($e->getPrevious());
        }
    }

    public function testErrorStatusRaisesARedactedDeliveryException(): void
    {
        $client = new MockHttpClient(new MockResponse('nope', ['http_code' => 500]));

        $channel = new WebhookNotificationChannel([
            'enabled' => true,
            'webhook' => ['enabled' => true, 'url' => 'https://user:pass@hooks.example.test:8443/uptime?token=SECRET'],
        ], $client);

        $this->expectException(NotificationDeliveryException::class);
        $this->expectExceptionMessage('webhook delivery to https://hooks.example.test:8443 failed: HTTP 500.');

        $channel->send($this->alert());
    }

    public function testRedactUrlRejectsInvalidUrls(): void
    {
        self::assertSame('[invalid url]', NotificationDeliveryException::redactUrl('not a url'));
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
