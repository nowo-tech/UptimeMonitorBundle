<?php

declare(strict_types=1);

namespace Nowo\UptimeMonitorBundle\Notification\Channel;

use DateTimeInterface;
use Nowo\UptimeMonitorBundle\Notification\NotificationChannelInterface;
use Nowo\UptimeMonitorBundle\Notification\NotificationDeliveryException;
use Nowo\UptimeMonitorBundle\Notification\UptimeAlert;
use Nowo\UptimeMonitorBundle\Security\SecretRedactor;
use ReflectionClass;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\Clock\NativeClock;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

use function is_numeric;
use function max;
use function sprintf;

/**
 * Posts uptime alerts as JSON to a generic webhook or a Slack incoming webhook.
 */
final readonly class WebhookNotificationChannel implements NotificationChannelInterface
{
    public const CHANNEL_GENERIC = 'webhook';
    public const CHANNEL_SLACK   = 'slack';

    /** Default for notifications.http_timeout (seconds): idle timeout and total deadline of one delivery. */
    public const DEFAULT_TIMEOUT_SECONDS = 10.0;

    /**
     * @param array<string, mixed> $notificationsConfig
     */
    public function __construct(
        #[Autowire('%nowo_uptime_monitor.notifications%')]
        private array $notificationsConfig,
        private HttpClientInterface $httpClient,
        private string $channelKey = self::CHANNEL_GENERIC,
        private ClockInterface $clock = new NativeClock(),
    ) {
    }

    /**
     * Returns the channel key (`webhook` or `slack`).
     */
    public function getName(): string
    {
        return $this->channelKey;
    }

    /**
     * Whether notifications and this channel are enabled and a URL is configured.
     */
    public function isEnabled(): bool
    {
        $config = $this->notificationsConfig[$this->channelKey] ?? [];

        return ($this->notificationsConfig['enabled'] ?? false)
            && ($config['enabled'] ?? false)
            && $this->resolveUrl($config) !== '';
    }

    /**
     * Posts the alert payload to the configured URL.
     *
     * @throws NotificationDeliveryException On a transport error or a non-2xx answer
     *
     * @return bool False when the channel is disabled
     */
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

        $timeout = $this->resolveTimeout();

        $statusCode = 0;
        $failure    = null;

        try {
            $response = $this->httpClient->request('POST', $url, [
                'json' => $payload,
                // Idle timeout + hard total deadline (REQ-RUNTIME-001): a slow endpoint never blocks the checks worker.
                'timeout'      => $timeout,
                'max_duration' => $timeout,
            ]);
            $statusCode = $response->getStatusCode();
        } catch (ExceptionInterface $e) {
            // Not chained on purpose: transport errors embed the full URL (webhook token) — REQ-OBS-001.
            $failure = (new ReflectionClass($e))->getShortName();
        }

        if ($failure !== null) {
            throw new NotificationDeliveryException(sprintf('%s delivery to %s failed (%s).', $this->channelKey, NotificationDeliveryException::redactUrl($url), $failure));
        }

        if ($statusCode >= 300) {
            throw new NotificationDeliveryException(sprintf('%s delivery to %s failed: HTTP %d.', $this->channelKey, NotificationDeliveryException::redactUrl($url), $statusCode));
        }

        return true;
    }

    private function resolveTimeout(): float
    {
        $timeout = $this->notificationsConfig['http_timeout'] ?? null;

        return is_numeric($timeout) ? max(1.0, (float) $timeout) : self::DEFAULT_TIMEOUT_SECONDS;
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
                'target' => SecretRedactor::maskUrl($monitor->getTarget()),
                'tenant' => $monitor->getTenant()->getSlug(),
            ],
            'status'          => $alert->getCurrentStatus()->value,
            'previous_status' => $alert->getPreviousStatus()?->value,
            'message'         => $alert->getMessage(),
            'timestamp'       => $this->clock->now()->format(DateTimeInterface::ATOM),
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
