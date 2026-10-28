<?php

declare(strict_types=1);

namespace Nowo\UptimeMonitorBundle\Entity;

use DateTimeImmutable;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Nowo\UptimeMonitorBundle\Enum\CheckStatus;
use Nowo\UptimeMonitorBundle\Enum\MonitorType;

use function sprintf;

/**
 * A monitored target (HTTP, TCP, DNS, SSL, ping, group or push) of a tenant, with its schedule and last known status.
 */
#[ORM\Entity]
#[ORM\Table(name: 'uptime_monitor')]
#[ORM\Index(name: 'idx_uptime_monitor_tenant_next', columns: ['tenant_id', 'next_check_at'])]
#[ORM\UniqueConstraint(name: 'uniq_uptime_monitor_push_key', columns: ['push_key'])]
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

    /** Push monitors: the opaque id of their ingest URL (never the numeric id, never the tenant). */
    #[ORM\Column(name: 'push_key', type: Types::STRING, length: 32, nullable: true)]
    private ?string $pushKey = null;

    /** Push monitors: SHA-256 of the bearer token (the token itself is shown once and never stored). */
    #[ORM\Column(name: 'push_token_hash', type: Types::STRING, length: 64, nullable: true)]
    private ?string $pushTokenHash = null;

    #[ORM\Column(name: 'push_last_at', type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?DateTimeImmutable $pushLastAt = null;

    /** @var array<string, mixed>|null last accepted push (validated metrics) */
    #[ORM\Column(name: 'push_sample', type: Types::JSON, nullable: true)]
    private ?array $pushSample = null;

    /**
     * Creates a monitor of a tenant, due for its first check now.
     *
     * @param string $target Checked target (URL, host:port, hostname or group name)
     */
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

    /**
     * Returns the database id (null until persisted).
     */
    public function getId(): ?int
    {
        return $this->id;
    }

    /**
     * Returns the owning tenant.
     */
    public function getTenant(): Tenant
    {
        return $this->tenant;
    }

    /**
     * Returns the display name.
     */
    public function getName(): string
    {
        return $this->name;
    }

    /**
     * Returns the project label used to group monitors without a parent group.
     */
    public function getProject(): ?string
    {
        return $this->project;
    }

    /**
     * Sets the project label (trimmed; an empty value clears it).
     */
    public function setProject(?string $project): self
    {
        $trimmed = $project !== null ? trim($project) : null;
        // @igor-ignore - Doctrine entity field; instance-scoped, not a shared service.
        $this->project = $trimmed === '' ? null : $trimmed;

        return $this;
    }

    /**
     * Returns the parent group, if any.
     */
    public function getParent(): ?self
    {
        return $this->parent;
    }

    /**
     * Sets the parent group; a monitor in a group takes the group name as project.
     */
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

    /**
     * Whether the monitor is a group of other monitors.
     */
    public function isGroup(): bool
    {
        return $this->type === MonitorType::Group;
    }

    /**
     * Returns how many consecutive failures are tolerated before the monitor is reported down.
     */
    public function getRetries(): int
    {
        $retries = $this->config['retries'] ?? 0;

        return is_numeric($retries) ? max(0, (int) $retries) : 0;
    }

    /**
     * Sets the tolerated consecutive failures (negative values become 0).
     */
    public function setRetries(int $retries): self
    {
        // @igor-ignore - Doctrine entity field; instance-scoped, not a shared service.
        $this->config['retries'] = max(0, $retries);

        return $this;
    }

    /**
     * Returns the interval between retries in seconds (at least 30).
     */
    public function getRetryIntervalSeconds(): int
    {
        $seconds = $this->config['retry_interval_seconds'] ?? 60;

        return is_numeric($seconds) ? max(30, (int) $seconds) : 60;
    }

    /**
     * Sets the interval between retries in seconds (at least 30).
     */
    public function setRetryIntervalSeconds(int $seconds): self
    {
        // @igor-ignore - Doctrine entity field; instance-scoped, not a shared service.
        $this->config['retry_interval_seconds'] = max(30, $seconds);

        return $this;
    }

    /**
     * Returns the check type.
     */
    public function getType(): MonitorType
    {
        return $this->type;
    }

    /**
     * Returns the checked target.
     */
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

    /**
     * Returns the interval between checks in seconds.
     */
    public function getIntervalSeconds(): int
    {
        return $this->intervalSeconds;
    }

    /**
     * Sets the interval between checks in seconds.
     */
    public function setIntervalSeconds(int $intervalSeconds): self
    {
        // @igor-ignore - Doctrine entity field; instance-scoped, not a shared service.
        $this->intervalSeconds = $intervalSeconds;

        return $this;
    }

    /**
     * Sets the display name.
     */
    public function setName(string $name): self
    {
        // @igor-ignore - Doctrine entity field; instance-scoped, not a shared service.
        $this->name = $name;

        return $this;
    }

    /**
     * Sets the check type.
     */
    public function setType(MonitorType $type): self
    {
        // @igor-ignore - Doctrine entity field; instance-scoped, not a shared service.
        $this->type = $type;

        return $this;
    }

    /**
     * Sets the checked target.
     */
    public function setTarget(string $target): self
    {
        // @igor-ignore - Doctrine entity field; instance-scoped, not a shared service.
        $this->target = $target;

        return $this;
    }

    /**
     * Whether the checks of this monitor are paused.
     */
    public function isPaused(): bool
    {
        return $this->paused;
    }

    /**
     * Pauses or resumes the checks.
     */
    public function setPaused(bool $paused): self
    {
        // @igor-ignore - Doctrine entity field; instance-scoped, not a shared service.
        $this->paused = $paused;

        return $this;
    }

    /**
     * Returns the status of the last check (after retries), if any.
     */
    public function getLastKnownStatus(): ?CheckStatus
    {
        return $this->lastKnownStatus;
    }

    /**
     * Sets the status of the last check.
     */
    public function setLastKnownStatus(?CheckStatus $lastKnownStatus): self
    {
        // @igor-ignore - Doctrine entity field; instance-scoped, not a shared service.
        $this->lastKnownStatus = $lastKnownStatus;

        return $this;
    }

    /**
     * Returns when the last notification was sent, if ever.
     */
    public function getLastAlertAt(): ?DateTimeImmutable
    {
        return $this->lastAlertAt;
    }

    /**
     * Sets when the last notification was sent.
     */
    public function setLastAlertAt(?DateTimeImmutable $lastAlertAt): self
    {
        // @igor-ignore - Doctrine entity field; instance-scoped, not a shared service.
        $this->lastAlertAt = $lastAlertAt;

        return $this;
    }

    /**
     * Returns when the next check is due (null = due now).
     */
    public function getNextCheckAt(): ?DateTimeImmutable
    {
        return $this->nextCheckAt;
    }

    /**
     * Sets when the next check is due.
     */
    public function setNextCheckAt(?DateTimeImmutable $nextCheckAt): self
    {
        // @igor-ignore - Doctrine entity field; instance-scoped, not a shared service.
        $this->nextCheckAt = $nextCheckAt;

        return $this;
    }

    /**
     * Returns the creation time.
     */
    public function getCreatedAt(): DateTimeImmutable
    {
        return $this->createdAt;
    }

    /**
     * Schedules the next check one interval after the given time.
     */
    public function scheduleNextCheck(DateTimeImmutable $from): self
    {
        // @igor-ignore - Doctrine entity field; instance-scoped, not a shared service.
        $this->nextCheckAt = $from->modify(sprintf('+%d seconds', $this->intervalSeconds));

        return $this;
    }

    /**
     * Returns the opaque key of the push URL (push monitors only).
     */
    public function getPushKey(): ?string
    {
        return $this->pushKey;
    }

    /**
     * Sets the opaque key of the push URL (null for other types).
     */
    public function setPushKey(?string $pushKey): self
    {
        // @igor-ignore - Doctrine entity field; instance-scoped, not a shared service.
        $this->pushKey = $pushKey;

        return $this;
    }

    /**
     * Returns the SHA-256 of the push token (null = no token: every push is refused).
     */
    public function getPushTokenHash(): ?string
    {
        return $this->pushTokenHash;
    }

    /**
     * Sets the SHA-256 of the push token (null revokes it).
     */
    public function setPushTokenHash(?string $pushTokenHash): self
    {
        // @igor-ignore - Doctrine entity field; instance-scoped, not a shared service.
        $this->pushTokenHash = $pushTokenHash;

        return $this;
    }

    /**
     * Returns when the last push was accepted.
     */
    public function getPushLastAt(): ?DateTimeImmutable
    {
        return $this->pushLastAt;
    }

    /**
     * Returns the metrics of the last accepted push.
     *
     * @return array<string, mixed>|null
     */
    public function getPushSample(): ?array
    {
        return $this->pushSample;
    }

    /**
     * Records an accepted push: its time and validated metrics.
     *
     * @param array<string, mixed> $sample
     */
    public function recordPush(DateTimeImmutable $at, array $sample): self
    {
        // @igor-ignore - Doctrine entity field; instance-scoped, not a shared service.
        $this->pushLastAt = $at;
        // @igor-ignore - Doctrine entity field; instance-scoped, not a shared service.
        $this->pushSample = $sample;

        return $this;
    }
}
