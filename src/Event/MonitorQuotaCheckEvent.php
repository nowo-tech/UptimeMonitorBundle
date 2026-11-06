<?php

declare(strict_types=1);

namespace Nowo\UptimeMonitorBundle\Event;

use Nowo\UptimeMonitorBundle\Entity\Monitor;
use Nowo\UptimeMonitorBundle\Enum\MonitorType;

/**
 * Cancellable quota check (since 1.21, {@see UptimeEvents::MONITOR_QUOTA_CHECK}): dispatched before the admin UI
 * or a backup import writes a monitor that may count against a host quota, inside the tenant row lock:
 *
 * - `create`: a new monitor (form or backup row), or an existing one changing type (group into check, `https` into
 *   `push`, ...; then `monitor` and `previousType` are set, and a listener that counts both types alike can ignore it);
 * - `resume`: a paused monitor being resumed, by the pause action, by an edit or by a backup overwrite.
 *
 * One edit can dispatch both, `create` first. `type` is always the type the monitor will have and `monitor` is not
 * changed yet. A listener calls {@see deny()} with the text the user sees (the monitor form error, a flash on the pause
 * action, the import warning); nothing is written then. A listener should not throw: the exception also refuses the
 * write, but it crosses the transaction and closes the EntityManager. Without a listener nothing changes; the bundle's
 * own `limits.provider` check still applies.
 */
final class MonitorQuotaCheckEvent
{
    /** A new monitor, or an existing one changing type. */
    public const ACTION_CREATE = 'create';

    /** A paused monitor being resumed. */
    public const ACTION_RESUME = 'resume';

    private ?string $reason = null;

    /**
     * Creates the check of one write.
     *
     * @param string $action {@see self::ACTION_CREATE} or {@see self::ACTION_RESUME}
     * @param string $tenantSlug Tenant of the monitor
     * @param MonitorType $type Type the monitor will have once written (groups included: the listener decides)
     * @param Monitor|null $monitor The existing monitor, not changed yet (null on a new monitor: it is not built yet)
     * @param MonitorType|null $previousType Type being replaced (`create` on a type change only)
     */
    public function __construct(
        public readonly string $action,
        public readonly string $tenantSlug,
        public readonly MonitorType $type,
        public readonly ?Monitor $monitor = null,
        public readonly ?MonitorType $previousType = null,
    ) {
    }

    /**
     * The checks of writing `$type` and `$paused` over an existing monitor: `create` when the type changes (the bundle
     * cannot know what a host counts separately), `resume` when a paused monitor stops being paused.
     *
     * @param string $tenantSlug Tenant of the monitor
     * @param Monitor $monitor The monitor, not changed yet
     * @param MonitorType $type Type being written
     * @param bool $paused Paused state being written
     *
     * @return list<self>
     */
    public static function forChange(string $tenantSlug, Monitor $monitor, MonitorType $type, bool $paused): array
    {
        $checks = [];
        if ($monitor->getType() !== $type) {
            $checks[] = new self(self::ACTION_CREATE, $tenantSlug, $type, $monitor, $monitor->getType());
        }
        if ($monitor->isPaused() && !$paused) {
            $checks[] = new self(self::ACTION_RESUME, $tenantSlug, $type, $monitor);
        }

        return $checks;
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
