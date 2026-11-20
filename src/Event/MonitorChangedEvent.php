<?php

declare(strict_types=1);

namespace Nowo\UptimeMonitorBundle\Event;

use DateTimeImmutable;
use Nowo\UptimeMonitorBundle\Audit\MonitorFieldChange;

use function array_map;

/**
 * A monitor was created, changed or deleted, through any write path of the bundle (since 1.33, `audit.enabled`): the
 * edit and new forms, delete, pause / resume, push token issue / revoke, the backup import, `nowo:uptime:apply-monitors`,
 * the demo seed and any host code that writes monitors through the bundle services.
 *
 * Dispatched once per change AFTER the commit: never inside the transaction, never for a write that was refused or
 * rolled back, and only once when a unit of work was replayed after a deadlock (the committed attempt's values). An
 * update that changed no audited field (only the check state) dispatches nothing.
 *
 * `changes` holds the structured diff: before → after for the plain fields, only `replaced` / `cleared` for the
 * write-only ones (headers, body, credentials, URL userinfo / path / query, push key and token). No value of a
 * write-only field and no hash of it is ever carried.
 */
final readonly class MonitorChangedEvent
{
    public const ACTION_CREATED = 'created';
    public const ACTION_UPDATED = 'updated';
    public const ACTION_DELETED = 'deleted';

    /**
     * Creates the event.
     *
     * @param string $tenantSlug Tenant of the monitor (a project UUID in Beacon)
     * @param int $monitorId Monitor id
     * @param string $action {@see self::ACTION_CREATED}, {@see self::ACTION_UPDATED} or {@see self::ACTION_DELETED}
     * @param list<MonitorFieldChange> $changes Field changes
     * @param string|null $actorId Actor identifier (personal data; null without a security token)
     * @param string $source Write path: `ui`, `import`, `apply`, `demo` or `app` (host code, console)
     * @param DateTimeImmutable $occurredAt When the change was written (inside the transaction that committed it)
     */
    public function __construct(
        public string $tenantSlug,
        public int $monitorId,
        public string $action,
        public array $changes,
        public ?string $actorId,
        public string $source,
        public DateTimeImmutable $occurredAt,
    ) {
    }

    /**
     * Names of the changed fields.
     *
     * @return list<string>
     */
    public function changedFields(): array
    {
        return array_map(static fn (MonitorFieldChange $change): string => $change->field, $this->changes);
    }

    /**
     * The changes as plain arrays (what the history stores).
     *
     * @return list<array{field: string, before?: mixed, after?: mixed, secret?: string}>
     */
    public function changesAsArrays(): array
    {
        return array_map(static fn (MonitorFieldChange $change): array => $change->toArray(), $this->changes);
    }
}
