<?php

declare(strict_types=1);

namespace Nowo\UptimeMonitorBundle\Event;

use DateTimeImmutable;

/**
 * A monitor was paused or resumed with the admin UI pause action (since 1.25). Dispatched after the commit, never when
 * a host quota refused the resume. Scalars only, like {@see MonitorsAppliedEvent}.
 */
final readonly class MonitorPausedEvent
{
    /**
     * Creates the event.
     *
     * @param string $tenantSlug Tenant of the monitor (a project UUID in Beacon)
     * @param int $monitorId Monitor id
     * @param bool $paused True when the monitor is now paused, false when it was resumed
     * @param string|null $actorId User identifier of the current user (null without a security token)
     * @param DateTimeImmutable $occurredAt When the write was committed
     */
    public function __construct(
        public string $tenantSlug,
        public int $monitorId,
        public bool $paused,
        public ?string $actorId,
        public DateTimeImmutable $occurredAt,
    ) {
    }
}
