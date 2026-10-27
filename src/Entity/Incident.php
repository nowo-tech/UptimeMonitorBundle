<?php

declare(strict_types=1);

namespace Nowo\UptimeMonitorBundle\Entity;

use DateTimeImmutable;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Nowo\UptimeMonitorBundle\Enum\CheckStatus;

/**
 * An outage of a monitor, from the first failing check until it is up again.
 */
#[ORM\Entity]
#[ORM\Table(name: 'uptime_incident')]
#[ORM\Index(name: 'idx_uptime_incident_monitor', columns: ['monitor_id', 'started_at'])]
class Incident
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: Types::INTEGER)]
    /** @phpstan-ignore property.unusedType (Doctrine sets id via reflection) */
    private ?int $id = null;

    #[ORM\Column(name: 'started_at', type: Types::DATETIME_IMMUTABLE)]
    private DateTimeImmutable $startedAt;

    #[ORM\Column(name: 'ended_at', type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?DateTimeImmutable $endedAt = null;

    /**
     * Opens an incident for a monitor.
     *
     * @param CheckStatus $triggerStatus Status that opened the incident (down or degraded)
     * @param string|null $message Message of the check that opened it
     * @param DateTimeImmutable|null $startedAt Start time (defaults to now)
     */
    public function __construct(#[ORM\ManyToOne(targetEntity: Monitor::class)]
        #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
        private Monitor $monitor, #[ORM\Column(type: Types::STRING, length: 16, enumType: CheckStatus::class)]
        private CheckStatus $triggerStatus, #[ORM\Column(type: Types::TEXT, nullable: true)]
        private ?string $message = null, ?DateTimeImmutable $startedAt = null)
    {
        // Services pass their injected clock time; the default keeps `new Incident(...)` usable on its own.
        $this->startedAt = $startedAt ?? new DateTimeImmutable();
    }

    /**
     * Returns the database id (null until persisted).
     */
    public function getId(): ?int
    {
        return $this->id;
    }

    /**
     * Returns the monitor of the incident.
     */
    public function getMonitor(): Monitor
    {
        return $this->monitor;
    }

    /**
     * Returns the status that opened the incident.
     */
    public function getTriggerStatus(): CheckStatus
    {
        return $this->triggerStatus;
    }

    /**
     * Returns the message of the check that opened the incident.
     */
    public function getMessage(): ?string
    {
        return $this->message;
    }

    /**
     * Returns the start time.
     */
    public function getStartedAt(): DateTimeImmutable
    {
        return $this->startedAt;
    }

    /**
     * Returns the resolution time (null while open).
     */
    public function getEndedAt(): ?DateTimeImmutable
    {
        return $this->endedAt;
    }

    /**
     * Whether the incident is not resolved yet.
     */
    public function isOpen(): bool
    {
        return !$this->endedAt instanceof DateTimeImmutable;
    }

    /**
     * Closes the incident at the given time.
     */
    public function resolve(DateTimeImmutable $endedAt = new DateTimeImmutable()): self
    {
        // @igor-ignore - Doctrine entity field; instance-scoped, not a shared service.
        $this->endedAt = $endedAt;

        return $this;
    }
}
