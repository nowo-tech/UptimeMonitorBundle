<?php

declare(strict_types=1);

namespace Nowo\UptimeMonitorBundle\Event;

use DateTimeImmutable;

/**
 * A monitor was saved from the admin UI edit form (since 1.25). Dispatched after the commit, never when the write was
 * refused or failed, also when no field changed (`changedFields` is then empty). Scalars only, like
 * {@see MonitorsAppliedEvent}: field names, never their values (the form holds credentials).
 */
final readonly class MonitorUpdatedEvent
{
    /**
     * Creates the event.
     *
     * @param string $tenantSlug Tenant of the monitor (a project UUID in Beacon)
     * @param int $monitorId Monitor id
     * @param list<string> $changedFields Names of the monitor form fields that changed (`name`, `url`, `paused`, …)
     * @param string|null $actorId User identifier of the current user (null without a security token)
     * @param DateTimeImmutable $occurredAt When the write was committed
     */
    public function __construct(
        public string $tenantSlug,
        public int $monitorId,
        public array $changedFields,
        public ?string $actorId,
        public DateTimeImmutable $occurredAt,
    ) {
    }
}
