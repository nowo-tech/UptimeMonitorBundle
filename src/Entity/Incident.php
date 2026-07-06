<?php

declare(strict_types=1);

namespace Nowo\UptimeMonitorBundle\Entity;

use DateTimeImmutable;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Nowo\UptimeMonitorBundle\Enum\CheckStatus;

#[ORM\Entity]
#[ORM\Table(name: 'uptime_incident')]
#[ORM\Index(name: 'idx_uptime_incident_monitor', columns: ['monitor_id', 'started_at'])]
class Incident
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: Types::INTEGER)]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Monitor::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Monitor $monitor;

    #[ORM\Column(name: 'started_at', type: Types::DATETIME_IMMUTABLE)]
    private DateTimeImmutable $startedAt;

    #[ORM\Column(name: 'ended_at', type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?DateTimeImmutable $endedAt = null;

    #[ORM\Column(type: Types::STRING, length: 16, enumType: CheckStatus::class)]
    private CheckStatus $triggerStatus;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $message = null;

    public function __construct(Monitor $monitor, CheckStatus $triggerStatus, ?string $message = null)
    {
        $this->monitor       = $monitor;
        $this->triggerStatus = $triggerStatus;
        $this->message       = $message;
        $this->startedAt     = new DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getMonitor(): Monitor
    {
        return $this->monitor;
    }

    public function getStartedAt(): DateTimeImmutable
    {
        return $this->startedAt;
    }

    public function getEndedAt(): ?DateTimeImmutable
    {
        return $this->endedAt;
    }

    public function isOpen(): bool
    {
        return $this->endedAt === null;
    }

    public function resolve(DateTimeImmutable $endedAt = new DateTimeImmutable()): self
    {
        $this->endedAt = $endedAt;

        return $this;
    }
}
