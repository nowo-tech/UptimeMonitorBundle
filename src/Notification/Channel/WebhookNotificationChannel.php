<?php

declare(strict_types=1);

namespace Nowo\UptimeMonitorBundle\Notification\Channel;

use DateTimeImmutable;
use DateTimeInterface;
use Nowo\UptimeMonitorBundle\Notification\NotificationChannelInterface;
use Nowo\UptimeMonitorBundle\Notification\UptimeAlert;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\HttpClient\HttpClientInterface;

final class WebhookNotificationChannel implements NotificationChannelInterface
{
    public const CHANNEL_GENERIC = 'webhook';
    public const CHANNEL_SLACK   = 'slack';

    /**
     * @param array<string, mixed> $notificationsConfig
     */
    public function __construct(
        #[Autowire('%nowo_uptime_monitor.notifications%')]
        private readonly array $notificationsConfig,
        private readonly HttpClientInterface $httpClient,
        private readonly string $channelKey = self::CHANNEL_GENERIC,
    ) {
    }

    public function getName(): string
    {
        return $this->channelKey;
    }

    public function isEnabled(): bool
    {
        $config = $this->notificationsConfig[$this->channelKey] ?? [];

        return ($this->notificationsConfig['enabled'] ?? false)
            && ($config['enabled'] ?? false)
            && $this->resolveUrl($config) !== '';
    }

    public function send(UptimeAlert $alert): bool
    {
        if (!$this->isEnabled()) {
            return false;
        }

        $config  = $this->notificationsConfig[$this->channelKey] ?? [];
        $url     = $this->resolveUrl($config);
        $payload = $this->channelKey === self::CHANNEL_SLACK
            ? $this->buildSlackPayload($alert)
            : $this->buildJsonPayload($alert);

        $this->httpClient->request('POST', $url, [
            'json'    => $payload,
            'timeout' => 10,
        ]);

        return true;
    }

    /**
     * @param array<string, mixed> $config
     */
    private function resolveUrl(array $config): string
    {
        if ($this->channelKey === self::CHANNEL_SLACK) {
            return (string) ($config['webhook_url'] ?? '');
        }

        return (string) ($config['url'] ?? '');
    }

    /**
     * @return array<string, mixed>
     */
    private function buildJsonPayload(UptimeAlert $alert): array
    {
        $monitor = $alert->getMonitor();

        return [
            'event'   => $alert->getTransition(),
            'monitor' => [
                'id'     => $monitor->getId(),
                'name'   => $monitor->getName(),
                'target' => $monitor->getTarget(),
                'tenant' => $monitor->getTenant()->getSlug(),
            ],
            'status'          => $alert->getCurrentStatus()->value,
            'previous_status' => $alert->getPreviousStatus()?->value,
            'message'         => $alert->getMessage(),
            'timestamp'       => (new DateTimeImmutable())->format(DateTimeInterface::ATOM),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function buildSlackPayload(UptimeAlert $alert): array
    {
        return [
            'text' => $alert->getSubject() . "\n" . $alert->getMessage(),
        ];
    }
}
