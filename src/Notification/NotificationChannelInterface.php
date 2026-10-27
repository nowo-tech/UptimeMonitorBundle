<?php

declare(strict_types=1);

namespace Nowo\UptimeMonitorBundle\Notification;

/**
 * Delivers uptime alerts on one channel (tagged `nowo.uptime_monitor.notification_channel`).
 */
interface NotificationChannelInterface
{
    /**
     * Delivers the alert.
     *
     * @throws NotificationDeliveryException When the delivery fails
     *
     * @return bool True when the alert was sent
     */
    public function send(UptimeAlert $alert): bool;

    /**
     * Whether the channel is enabled and configured.
     */
    public function isEnabled(): bool;

    /**
     * Returns the channel key (`email`, `webhook`, `slack`, ...).
     */
    public function getName(): string;
}
