<?php

declare(strict_types=1);

namespace Nowo\UptimeMonitorBundle\Event;

use DateTimeImmutable;

/**
 * A monitor was deleted from the admin UI (since 1.25). Dispatched after the commit, never when the write was refused or failed.
 * Scalars only, like {@see MonitorsAppliedEvent}: a host writes its audit or projection from it.
 */
final readonly class MonitorDeletedEvent
{
    /**
     * Creates the event.
     *
     * @param string $tenantSlug Tenant of the monitor (a project UUID in Beacon)
     * @param int $monitorId Monitor id
     * @param string|null $actorId User identifier of the current user (null without a security token)
     * @param DateTimeImmutable $occurredAt When the write was committed
     */
    public function __construct(
        public string $tenantSlug,
        public int $monitorId,
        public ?string $actorId,
        public DateTimeImmutable $occurredAt,
    ) {
    }
}
