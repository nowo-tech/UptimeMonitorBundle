<?php

declare(strict_types=1);

namespace Nowo\UptimeMonitorBundle\Notification;

interface NotificationChannelInterface
{
    public function send(UptimeAlert $alert): bool;

    public function isEnabled(): bool;

    public function getName(): string;
}
