<?php

declare(strict_types=1);

namespace Nowo\UptimeMonitorBundle\Entity;

use DateTimeImmutable;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Nowo\UptimeMonitorBundle\Enum\CheckStatus;
use Nowo\UptimeMonitorBundle\Enum\MonitorType;

use function sprintf;

#[ORM\Entity]
#[ORM\Table(name: 'uptime_monitor')]
#[ORM\Index(name: 'idx_uptime_monitor_tenant_next', columns: ['tenant_id', 'next_check_at'])]
class Monitor
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: Types::INTEGER)]
    /** @phpstan-ignore property.unusedType (Doctrine sets id via reflection) */
    private ?int $id = null;

    #[ORM\Column(type: Types::STRING, length: 128, nullable: true)]
    private ?string $project = null;

    #[ORM\ManyToOne(targetEntity: self::class)]
    #[ORM\JoinColumn(name: 'parent_id', nullable: true, onDelete: 'SET NULL')]
    private ?Monitor $parent = null;

    /** @var array<string, mixed> */
    #[ORM\Column(type: Types::JSON)]
    private array $config = [];

    #[ORM\Column(type: Types::INTEGER)]
    private int $intervalSeconds = 60;

    #[ORM\Column(type: Types::BOOLEAN)]
    private bool $paused = false;

    #[ORM\Column(name: 'next_check_at', type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?DateTimeImmutable $nextCheckAt;

    #[ORM\Column(name: 'last_known_status', type: Types::STRING, length: 16, nullable: true, enumType: CheckStatus::class)]
    private ?CheckStatus $lastKnownStatus = null;

    #[ORM\Column(name: 'last_alert_at', type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?DateTimeImmutable $lastAlertAt = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private DateTimeImmutable $createdAt;

    public function __construct(#[ORM\ManyToOne(targetEntity: Tenant::class)]
        #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
        private Tenant $tenant, #[ORM\Column(type: Types::STRING, length: 255)]
        private string $name, #[ORM\Column(type: Types::STRING, length: 16, enumType: MonitorType::class)]
        private MonitorType $type, #[ORM\Column(type: Types::STRING, length: 2048)]
        private string $target)
    {
        $this->createdAt   = new DateTimeImmutable();
        $this->nextCheckAt = new DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getTenant(): Tenant
    {
        return $this->tenant;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getProject(): ?string
    {
        return $this->project;
    }

    public function setProject(?string $project): self
    {
        $trimmed = $project !== null ? trim($project) : null;
        // @igor-ignore - Doctrine entity field; instance-scoped, not a shared service.
        $this->project = $trimmed === '' ? null : $trimmed;

        return $this;
    }

    public function getParent(): ?self
    {
        return $this->parent;
    }

    public function setParent(?self $parent): self
    {
        // @igor-ignore - Doctrine entity field; instance-scoped, not a shared service.
        $this->parent = $parent;
        if ($parent instanceof self) {
            // @igor-ignore - Doctrine entity field; instance-scoped, not a shared service.
            $this->project = $parent->getName();
        }

        return $this;
    }

    public function isGroup(): bool
    {
        return $this->type === MonitorType::Group;
    }

    public function getRetries(): int
    {
        $retries = $this->config['retries'] ?? 0;

        return is_numeric($retries) ? max(0, (int) $retries) : 0;
    }

    public function setRetries(int $retries): self
    {
        // @igor-ignore - Doctrine entity field; instance-scoped, not a shared service.
        $this->config['retries'] = max(0, $retries);

        return $this;
    }

    public function getRetryIntervalSeconds(): int
    {
        $seconds = $this->config['retry_interval_seconds'] ?? 60;

        return is_numeric($seconds) ? max(30, (int) $seconds) : 60;
    }

    public function setRetryIntervalSeconds(int $seconds): self
    {
        // @igor-ignore - Doctrine entity field; instance-scoped, not a shared service.
        $this->config['retry_interval_seconds'] = max(30, $seconds);

        return $this;
    }

    public function getType(): MonitorType
    {
        return $this->type;
    }

    public function getTarget(): string
    {
        return $this->target;
    }

    /** @return array<string, mixed> */
    public function getConfig(): array
    {
        return $this->config;
    }

    /** @param array<string, mixed> $config */
    public function setConfig(array $config): self
    {
        // @igor-ignore - Doctrine entity field; instance-scoped, not a shared service.
        $this->config = $config;

        return $this;
    }

    public function getIntervalSeconds(): int
    {
        return $this->intervalSeconds;
    }

    public function setIntervalSeconds(int $intervalSeconds): self
    {
        // @igor-ignore - Doctrine entity field; instance-scoped, not a shared service.
        $this->intervalSeconds = $intervalSeconds;

        return $this;
    }

    public function setName(string $name): self
    {
        // @igor-ignore - Doctrine entity field; instance-scoped, not a shared service.
        $this->name = $name;

        return $this;
    }

    public function setType(MonitorType $type): self
    {
        // @igor-ignore - Doctrine entity field; instance-scoped, not a shared service.
        $this->type = $type;

        return $this;
    }

    public function setTarget(string $target): self
    {
        // @igor-ignore - Doctrine entity field; instance-scoped, not a shared service.
        $this->target = $target;

        return $this;
    }

    public function isPaused(): bool
    {
        return $this->paused;
    }

    public function setPaused(bool $paused): self
    {
        // @igor-ignore - Doctrine entity field; instance-scoped, not a shared service.
        $this->paused = $paused;

        return $this;
    }

    public function getLastKnownStatus(): ?CheckStatus
    {
        return $this->lastKnownStatus;
    }

    public function setLastKnownStatus(?CheckStatus $lastKnownStatus): self
    {
        // @igor-ignore - Doctrine entity field; instance-scoped, not a shared service.
        $this->lastKnownStatus = $lastKnownStatus;

        return $this;
    }

    public function getLastAlertAt(): ?DateTimeImmutable
    {
        return $this->lastAlertAt;
    }

    public function setLastAlertAt(?DateTimeImmutable $lastAlertAt): self
    {
        // @igor-ignore - Doctrine entity field; instance-scoped, not a shared service.
        $this->lastAlertAt = $lastAlertAt;

        return $this;
    }

    public function getNextCheckAt(): ?DateTimeImmutable
    {
        return $this->nextCheckAt;
    }

    public function setNextCheckAt(?DateTimeImmutable $nextCheckAt): self
    {
        // @igor-ignore - Doctrine entity field; instance-scoped, not a shared service.
        $this->nextCheckAt = $nextCheckAt;

        return $this;
    }

    public function getCreatedAt(): DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function scheduleNextCheck(DateTimeImmutable $from): self
    {
        // @igor-ignore - Doctrine entity field; instance-scoped, not a shared service.
        $this->nextCheckAt = $from->modify(sprintf('+%d seconds', $this->intervalSeconds));

        return $this;
    }
}
