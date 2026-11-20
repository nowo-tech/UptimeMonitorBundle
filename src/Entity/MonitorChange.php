<?php

declare(strict_types=1);

namespace Nowo\UptimeMonitorBundle\Entity;

use DateTimeImmutable;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Nowo\UptimeMonitorBundle\Audit\MonitorChangeCapture;
use Nowo\UptimeMonitorBundle\Audit\MonitorFieldChange;

/**
 * One recorded change of a monitor (since 1.33, `audit.history.enabled`): who changed it, when, through which path,
 * and the field changes (before → after for the plain fields, only `replaced` / `cleared` for the write-only ones; see
 * {@see MonitorFieldChange}). Written by the bundle in the same transaction as the change itself
 * ({@see MonitorChangeCapture}), never updated afterwards except to anonymise the actor.
 *
 * `monitor_id` is a plain column, not a foreign key: the history of a deleted monitor stays until the retention purge.
 * The tenant is a foreign key with `ON DELETE CASCADE`: removing a tenant removes its history.
 */
#[ORM\Entity]
#[ORM\Table(name: 'uptime_monitor_change')]
#[ORM\Index(name: 'idx_uptime_monitor_change_monitor', columns: ['monitor_id', 'occurred_at'])]
#[ORM\Index(name: 'idx_uptime_monitor_change_occurred', columns: ['occurred_at'])]
class MonitorChange
{
    /** What the actor of a row becomes once it is anonymised (GDPR erasure). */
    public const ANONYMISED_ACTOR = '(anonymised)';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: Types::INTEGER)]
    /** @phpstan-ignore property.unusedType (Doctrine sets id via reflection) */
    private ?int $id = null;

    /**
     * Creates a history row.
     *
     * @param Tenant $tenant Tenant of the monitor
     * @param int $monitorId Monitor id (kept after the monitor is deleted)
     * @param string $action `created`, `updated` or `deleted`
     * @param string $source Write path (`ui`, `import`, `apply`, `demo`, `app`)
     * @param string|null $actor Actor identifier (personal data; null without a security token)
     * @param list<array<string, mixed>> $changes Field changes ({@see MonitorFieldChange::toArray()})
     * @param DateTimeImmutable $occurredAt When the change was written
     */
    public function __construct(
        #[ORM\ManyToOne(targetEntity: Tenant::class)]
        #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
        private Tenant $tenant,
        #[ORM\Column(name: 'monitor_id', type: Types::INTEGER)]
        private int $monitorId,
        #[ORM\Column(type: Types::STRING, length: 8)]
        private string $action,
        #[ORM\Column(type: Types::STRING, length: 16)]
        private string $source,
        #[ORM\Column(type: Types::STRING, length: 255, nullable: true)]
        private ?string $actor,
        #[ORM\Column(type: Types::JSON)]
        private array $changes,
        #[ORM\Column(name: 'occurred_at', type: Types::DATETIME_IMMUTABLE)]
        private DateTimeImmutable $occurredAt,
    ) {
    }

    /**
     * Returns the database id (null until persisted).
     */
    public function getId(): ?int
    {
        return $this->id;
    }

    /**
     * Returns the tenant of the monitor.
     */
    public function getTenant(): Tenant
    {
        return $this->tenant;
    }

    /**
     * Returns the monitor id.
     */
    public function getMonitorId(): int
    {
        return $this->monitorId;
    }

    /**
     * Returns `created`, `updated` or `deleted`.
     */
    public function getAction(): string
    {
        return $this->action;
    }

    /**
     * Returns the write path.
     */
    public function getSource(): string
    {
        return $this->source;
    }

    /**
     * Returns the actor identifier (null without a security token, {@see self::ANONYMISED_ACTOR} once erased).
     */
    public function getActor(): ?string
    {
        return $this->actor;
    }

    /**
     * Returns the field changes.
     *
     * @return list<MonitorFieldChange>
     */
    public function getChanges(): array
    {
        $changes = [];
        foreach ($this->changes as $change) {
            $changes[] = MonitorFieldChange::fromArray($change);
        }

        return $changes;
    }

    /**
     * Returns when the change was written.
     */
    public function getOccurredAt(): DateTimeImmutable
    {
        return $this->occurredAt;
    }
}
