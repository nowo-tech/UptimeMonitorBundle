<?php

declare(strict_types=1);

namespace Nowo\UptimeMonitorBundle\Query;

use Nowo\UptimeMonitorBundle\Enum\CheckStatus;
use Nowo\UptimeMonitorBundle\Enum\MonitorType;
use Nowo\UptimeMonitorBundle\Security\SecretRedactor;

/**
 * Read model of one monitor (since 1.35, {@see MonitorQueryInterface}): plain values, never an entity. The target is
 * masked ({@see SecretRedactor::maskUrl()}): credentials and secret query values never reach a list.
 */
final readonly class MonitorView
{
    /**
     * @param int $id Monitor id
     * @param string $tenantSlug Slug of its tenant
     * @param string $tenantName Display name of its tenant
     * @param string $name Monitor name
     * @param MonitorType $type Monitor type
     * @param string $target Target, masked
     * @param int $intervalSeconds Check interval
     * @param bool $paused Whether the monitor is paused
     * @param CheckStatus|null $lastKnownStatus Status of the last check (null: never checked)
     * @param int|null $parentId Id of the parent group, if any
     * @param LatestCheckView|null $latest Latest stored check result (null: none, or not requested)
     */
    public function __construct(
        public int $id,
        public string $tenantSlug,
        public string $tenantName,
        public string $name,
        public MonitorType $type,
        public string $target,
        public int $intervalSeconds,
        public bool $paused,
        public ?CheckStatus $lastKnownStatus,
        public ?int $parentId = null,
        public ?LatestCheckView $latest = null,
    ) {
    }

    /**
     * The status a list shows: `paused`, else the last known status, else `unknown`.
     */
    public function displayStatus(): string
    {
        return $this->paused ? MonitorListStatus::Paused->value : ($this->lastKnownStatus ?? CheckStatus::Unknown)->value;
    }

    /**
     * The same view with its latest check result.
     */
    public function withLatest(?LatestCheckView $latest): self
    {
        return new self($this->id, $this->tenantSlug, $this->tenantName, $this->name, $this->type, $this->target, $this->intervalSeconds, $this->paused, $this->lastKnownStatus, $this->parentId, $latest);
    }
}
