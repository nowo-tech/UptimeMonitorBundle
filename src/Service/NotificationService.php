<?php

declare(strict_types=1);

namespace Nowo\UptimeMonitorBundle\Service;

use Nowo\UptimeMonitorBundle\Notification\NotificationChannelInterface;
use Nowo\UptimeMonitorBundle\Notification\UptimeAlert;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;
use Throwable;

class NotificationService
{
    /**
     * @param iterable<NotificationChannelInterface> $channels
     * @param array<string, mixed> $notificationsConfig
     */
    public function __construct(
        #[AutowireIterator('nowo.uptime_monitor.notification_channel')]
        private readonly iterable $channels,
        #[Autowire('%nowo_uptime_monitor.notifications%')]
        private readonly array $notificationsConfig,
        private readonly ?LoggerInterface $logger = null,
    ) {
    }

    public function notify(UptimeAlert $alert): int
    {
        if (!($this->notificationsConfig['enabled'] ?? false)) {
            return 0;
        }

        $sent = 0;
        foreach ($this->channels as $channel) {
            if (!$channel->isEnabled()) {
                continue;
            }

            try {
                if ($channel->send($alert)) {
                    ++$sent;
                }
            } catch (Throwable $e) {
                $this->logger?->error('Uptime notification failed on channel {channel}: {error}', [
                    'channel' => $channel->getName(),
                    'error'   => $e->getMessage(),
                ]);
            }
        }

        return $sent;
    }
}
