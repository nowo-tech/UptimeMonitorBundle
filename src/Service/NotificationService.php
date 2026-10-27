<?php

declare(strict_types=1);

namespace Nowo\UptimeMonitorBundle\Service;

use Nowo\UptimeMonitorBundle\Notification\NotificationChannelInterface;
use Nowo\UptimeMonitorBundle\Notification\NotificationDeliveryException;
use Nowo\UptimeMonitorBundle\Notification\UptimeAlert;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;
use Throwable;

/**
 * Sends an {@see UptimeAlert} through every enabled channel.
 *
 * Logging policy (REQ-OBS-001, docs/SECURITY.md): structured context only — channel name, monitor id, transition and
 * a safe error text ({@see NotificationDeliveryException} message, otherwise the exception class). Never the
 * webhook URL, tokens, recipients, monitor target or alert body.
 */
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

    /**
     * Sends the alert through every enabled channel; a failing channel is logged and skipped.
     *
     * @return int Number of channels that delivered the alert
     */
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

            $context = [
                'bundle'     => 'NowoUptimeMonitorBundle',
                'channel'    => $channel->getName(),
                'monitor_id' => $alert->getMonitor()->getId(),
                'transition' => $alert->getTransition(),
            ];

            try {
                $this->logger?->debug('Uptime notification sending on channel {channel}', $context);
                if ($channel->send($alert)) {
                    ++$sent;
                    $this->logger?->info('Uptime notification sent on channel {channel}', $context);
                }
            } catch (Throwable $e) {
                $this->logger?->error('Uptime notification failed on channel {channel}: {error}', $context + [
                    // Only messages built to be safe are logged; other exceptions may embed URLs, tokens or addresses.
                    'error' => $e instanceof NotificationDeliveryException ? $e->getMessage() : $e::class,
                ]);
            }
        }

        return $sent;
    }
}
