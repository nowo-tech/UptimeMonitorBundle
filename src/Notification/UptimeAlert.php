<?php

declare(strict_types=1);

namespace Nowo\UptimeMonitorBundle\Notification;

use Nowo\UptimeMonitorBundle\Entity\Monitor;
use Nowo\UptimeMonitorBundle\Enum\CheckStatus;

use function sprintf;

/**
 * Alert payload for uptime status transitions.
 */
final class UptimeAlert
{
    public const TRANSITION_DOWN = 'down';
    public const TRANSITION_UP   = 'recovery';

    public function __construct(
        private readonly Monitor $monitor,
        private readonly string $transition,
        private readonly CheckStatus $currentStatus,
        private readonly ?CheckStatus $previousStatus,
        private readonly ?string $message = null,
    ) {
    }

    public function getMonitor(): Monitor
    {
        return $this->monitor;
    }

    public function getTransition(): string
    {
        return $this->transition;
    }

    public function getCurrentStatus(): CheckStatus
    {
        return $this->currentStatus;
    }

    public function getPreviousStatus(): ?CheckStatus
    {
        return $this->previousStatus;
    }

    public function getMessage(): string
    {
        if ($this->message !== null && $this->message !== '') {
            return $this->message;
        }

        return sprintf(
            'Monitor "%s" (%s) is now %s',
            $this->monitor->getName(),
            $this->monitor->getTarget(),
            $this->currentStatus->value,
        );
    }

    public function getSubject(): string
    {
        $emoji = $this->transition === self::TRANSITION_DOWN ? '🔴' : '🟢';

        return sprintf('%s Uptime: %s', $emoji, $this->monitor->getName());
    }
}
