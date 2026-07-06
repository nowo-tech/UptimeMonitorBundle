<?php

declare(strict_types=1);

namespace Nowo\UptimeMonitorBundle\Entity;

use DateTimeImmutable;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Nowo\UptimeMonitorBundle\Enum\CheckStatus;

#[ORM\Entity]
#[ORM\Table(name: 'uptime_check_result')]
#[ORM\Index(name: 'idx_uptime_check_monitor_checked', columns: ['monitor_id', 'checked_at'])]
class CheckResult
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: Types::INTEGER)]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Monitor::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Monitor $monitor;

    #[ORM\Column(type: Types::STRING, length: 16, enumType: CheckStatus::class)]
    private CheckStatus $status;

    #[ORM\Column(type: Types::INTEGER)]
    private int $latencyMs;

    #[ORM\Column(type: Types::INTEGER, nullable: true)]
    private ?int $statusCode = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $message = null;

    /** @var array<string, mixed>|null */
    #[ORM\Column(type: Types::JSON, nullable: true)]
    private ?array $metadata = null;

    #[ORM\Column(name: 'checked_at', type: Types::DATETIME_IMMUTABLE)]
    private DateTimeImmutable $checkedAt;

    /**
     * @param array<string, mixed>|null $metadata
     */
    public function __construct(
        Monitor $monitor,
        CheckStatus $status,
        int $latencyMs,
        ?int $statusCode = null,
        ?string $message = null,
        ?array $metadata = null,
    ) {
        $this->monitor    = $monitor;
        $this->status     = $status;
        $this->latencyMs  = $latencyMs;
        $this->statusCode = $statusCode;
        $this->message    = $message;
        $this->metadata   = $metadata;
        $this->checkedAt  = new DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getMonitor(): Monitor
    {
        return $this->monitor;
    }

    public function getStatus(): CheckStatus
    {
        return $this->status;
    }

    public function getLatencyMs(): int
    {
        return $this->latencyMs;
    }

    public function getStatusCode(): ?int
    {
        return $this->statusCode;
    }

    public function getMessage(): ?string
    {
        return $this->message;
    }

    public function getCheckedAt(): DateTimeImmutable
    {
        return $this->checkedAt;
    }
}
