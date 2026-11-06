<?php

declare(strict_types=1);

namespace Nowo\UptimeMonitorBundle\Event;

use Nowo\UptimeMonitorBundle\Entity\Monitor;
use Nowo\UptimeMonitorBundle\Enum\MonitorType;

/**
 * Cancellable quota check (since 1.21, {@see UptimeEvents::MONITOR_QUOTA_CHECK}): dispatched before the admin UI
 * writes a monitor that may count against a host quota — a new monitor (`create`, inside the tenant row lock when the
 * monitor takes a `limits` slot) and resuming a paused one (`resume`). A listener calls {@see deny()} with the text
 * the user sees (the monitor form error, or a flash on resume); nothing is written then. Without a listener nothing
 * changes; the bundle's own `limits.provider` check still applies.
 */
final class MonitorQuotaCheckEvent
{
    /** A new monitor from the monitor form. */
    public const ACTION_CREATE = 'create';

    /** A paused monitor being resumed. */
    public const ACTION_RESUME = 'resume';

    private ?string $reason = null;

    /**
     * Creates the check of one write.
     *
     * @param string $action {@see self::ACTION_CREATE} or {@see self::ACTION_RESUME}
     * @param string $tenantSlug Tenant of the monitor
     * @param MonitorType $type Type of the monitor being created or resumed (groups included: the listener decides)
     * @param Monitor|null $monitor The monitor being resumed (null on create: it is not built yet)
     */
    public function __construct(
        public readonly string $action,
        public readonly string $tenantSlug,
        public readonly MonitorType $type,
        public readonly ?Monitor $monitor = null,
    ) {
    }

    /**
     * Refuses the write. `$reason` is plain text shown to the user (escaped when rendered); the first denial wins.
     */
    public function deny(string $reason): void
    {
        // @igor-ignore - Request-scoped event object; not a shared worker service.
        $this->reason ??= $reason;
    }

    /**
     * Whether a listener refused the write.
     */
    public function isDenied(): bool
    {
        return $this->reason !== null;
    }

    /**
     * The text of the first denial; null when the write is allowed.
     */
    public function getReason(): ?string
    {
        return $this->reason;
    }
}
