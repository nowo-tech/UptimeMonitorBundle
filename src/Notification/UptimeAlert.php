<?php

declare(strict_types=1);

namespace Nowo\UptimeMonitorBundle\Notification;

use Nowo\UptimeMonitorBundle\Entity\Monitor;
use Nowo\UptimeMonitorBundle\Enum\CheckStatus;

use function sprintf;

/**
 * Alert payload for uptime status transitions.
 */
final readonly class UptimeAlert
{
    public const TRANSITION_DOWN = 'down';
    public const TRANSITION_UP   = 'recovery';

    /**
     * Creates an alert for a status transition of a monitor.
     *
     * @param string $transition One of the TRANSITION_* constants
     * @param CheckStatus|null $previousStatus Status before the transition (null on the first check)
     * @param string|null $message Check message; a default text is built when null
     */
    public function __construct(
        private Monitor $monitor,
        private string $transition,
        private CheckStatus $currentStatus,
        private ?CheckStatus $previousStatus,
        private ?string $message = null,
    ) {
    }

    /**
     * Returns the monitor the alert is about.
     */
    public function getMonitor(): Monitor
    {
        return $this->monitor;
    }

    /**
     * Returns the transition (TRANSITION_DOWN or TRANSITION_UP).
     */
    public function getTransition(): string
    {
        return $this->transition;
    }

    /**
     * Returns the status after the transition.
     */
    public function getCurrentStatus(): CheckStatus
    {
        return $this->currentStatus;
    }

    /**
     * Returns the status before the transition, if known.
     */
    public function getPreviousStatus(): ?CheckStatus
    {
        return $this->previousStatus;
    }

    /**
     * Returns the alert text (the check message or a default one).
     */
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

    /**
     * Returns the subject line used by email and Slack.
     */
    public function getSubject(): string
    {
        $emoji = $this->transition === self::TRANSITION_DOWN ? '🔴' : '🟢';

        return sprintf('%s Uptime: %s', $emoji, $this->monitor->getName());
    }
}
