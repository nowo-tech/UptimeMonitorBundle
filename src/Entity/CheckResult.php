<?php

declare(strict_types=1);

namespace Nowo\UptimeMonitorBundle\Entity;

use DateTimeImmutable;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Nowo\UptimeMonitorBundle\Enum\CheckStatus;

/**
 * The stored outcome of one monitor check (detail row, purged by the retention).
 */
#[ORM\Entity]
#[ORM\Table(name: 'uptime_check_result')]
#[ORM\Index(name: 'idx_uptime_check_monitor_checked', columns: ['monitor_id', 'checked_at'])]
class CheckResult
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: Types::INTEGER)]
    /** @phpstan-ignore property.unusedType (Doctrine sets id via reflection) */
    private ?int $id = null;

    #[ORM\Column(name: 'checked_at', type: Types::DATETIME_IMMUTABLE)]
    private DateTimeImmutable $checkedAt;

    /**
     * @param array<string, mixed>|null $metadata
     * @param DateTimeImmutable|null $checkedAt Check time (defaults to now)
     */
    public function __construct(
        #[ORM\ManyToOne(targetEntity: Monitor::class)]
        #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
        private Monitor $monitor,
        #[ORM\Column(type: Types::STRING, length: 16, enumType: CheckStatus::class)]
        private CheckStatus $status,
        #[ORM\Column(type: Types::INTEGER)]
        private int $latencyMs,
        #[ORM\Column(type: Types::INTEGER, nullable: true)]
        private ?int $statusCode = null,
        #[ORM\Column(type: Types::TEXT, nullable: true)]
        private ?string $message = null,
        #[ORM\Column(type: Types::JSON, nullable: true)]
        private ?array $metadata = null,
        ?DateTimeImmutable $checkedAt = null,
    ) {
        // Services pass their injected clock time; the default keeps `new CheckResult(...)` usable on its own.
        $this->checkedAt = $checkedAt ?? new DateTimeImmutable();
    }

    /**
     * Returns the database id (null until persisted).
     */
    public function getId(): ?int
    {
        return $this->id;
    }

    /**
     * Returns the checked monitor.
     */
    public function getMonitor(): Monitor
    {
        return $this->monitor;
    }

    /**
     * Returns the check status.
     */
    public function getStatus(): CheckStatus
    {
        return $this->status;
    }

    /**
     * Returns the latency in milliseconds.
     */
    public function getLatencyMs(): int
    {
        return $this->latencyMs;
    }

    /**
     * Returns the HTTP status code, for HTTP checks.
     */
    public function getStatusCode(): ?int
    {
        return $this->statusCode;
    }

    /**
     * Returns the failure or info message, if any.
     */
    public function getMessage(): ?string
    {
        return $this->message;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function getMetadata(): ?array
    {
        return $this->metadata;
    }

    /**
     * Returns when the check ran.
     */
    public function getCheckedAt(): DateTimeImmutable
    {
        return $this->checkedAt;
    }
}
