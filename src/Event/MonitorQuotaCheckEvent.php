<?php

declare(strict_types=1);

namespace Nowo\UptimeMonitorBundle\Event;

use LogicException;
use Nowo\UptimeMonitorBundle\Entity\Monitor;
use Nowo\UptimeMonitorBundle\Enum\MonitorType;

use function sprintf;
use function trigger_deprecation;

/**
 * Cancellable quota check (since 1.21, {@see UptimeEvents::MONITOR_QUOTA_CHECK}): dispatched before the admin UI
 * or a backup import writes a monitor that may count against a host quota, inside the tenant row lock:
 *
 * - `create`: a new monitor (form or backup row), or an existing one changing type (group into check, `https` into
 *   `push`, ...; then `monitor` and `previousType` are set, and a listener that counts both types alike can ignore it);
 * - `resume`: a paused monitor being resumed, by the pause action, by an edit or by a backup overwrite.
 *
 * One edit can dispatch both, `create` first. `type` is always the type the monitor will have. A listener calls
 * {@see deny()} with the text the user sees (the monitor form error, a flash on the pause action, the import warning);
 * nothing is written then. A listener should not throw: the exception also refuses the write, but it crosses the
 * transaction and closes the EntityManager. Without a listener nothing changes; the bundle's own `limits.provider`
 * check still applies.
 *
 * Since 1.35 (REQ-ARCH-001 rules 1 and 4) the event carries scalars: `monitorId` (null on a new monitor) and `paused`
 * (an existing monitor: whether it is paused before this write; a new monitor: whether it is created paused). The
 * entity accessor `monitor` is deprecated since 1.35 and removed in 2.0: reading it triggers a deprecation.
 *
 * @property Monitor|null $monitor Deprecated since 1.35, removed in 2.0: use `monitorId` and `paused`. The
 *                                 existing monitor, not changed yet (null on a new monitor)
 */
final class MonitorQuotaCheckEvent
{
    /** A new monitor, or an existing one changing type. */
    public const ACTION_CREATE = 'create';

    /** A paused monitor being resumed. */
    public const ACTION_RESUME = 'resume';

    /** Id of the existing monitor; null on a new monitor (since 1.35). */
    public readonly ?int $monitorId;

    /** An existing monitor: whether it is paused before this write; a new one: whether it is created paused (since 1.35). */
    public readonly bool $paused;

    private ?string $reason = null;

    /** The existing monitor behind the deprecated `monitor` accessor. */
    private readonly ?Monitor $existing;

    /**
     * Creates the check of one write.
     *
     * @param string $action {@see self::ACTION_CREATE} or {@see self::ACTION_RESUME}
     * @param string $tenantSlug Tenant of the monitor
     * @param MonitorType $type Type the monitor will have once written (groups included: the listener decides)
     * @param Monitor|null $monitor The existing monitor, not changed yet (null on a new monitor: it is not built yet)
     * @param MonitorType|null $previousType Type being replaced (`create` on a type change only)
     * @param bool|null $paused Since 1.35: the paused state (null = the existing monitor's, else false)
     * @param int|null $monitorId Since 1.35: the existing monitor's id (null = the id of `$monitor`)
     */
    public function __construct(
        public readonly string $action,
        public readonly string $tenantSlug,
        public readonly MonitorType $type,
        ?Monitor $monitor = null,
        public readonly ?MonitorType $previousType = null,
        ?bool $paused = null,
        ?int $monitorId = null,
    ) {
        $this->existing  = $monitor;
        $this->paused    = $paused ?? ($monitor?->isPaused() ?? false);
        $this->monitorId = $monitorId ?? $monitor?->getId();
    }

    /**
     * The deprecated `monitor` accessor (since 1.35; removed in 2.0).
     *
     * @throws LogicException For any other undefined property
     */
    public function __get(string $name): mixed
    {
        if ($name !== 'monitor') {
            throw new LogicException(sprintf('Undefined property %s::$%s.', self::class, $name));
        }
        trigger_deprecation('nowo-tech/uptime-monitor-bundle', '1.35', 'Reading %s::$monitor is deprecated and the property is removed in 2.0: use $monitorId and $paused.', self::class);

        return $this->existing;
    }

    /**
     * Whether the deprecated `monitor` accessor holds a monitor (no deprecation: `isset()` reads nothing).
     */
    public function __isset(string $name): bool
    {
        return $name === 'monitor' && $this->existing instanceof Monitor;
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
