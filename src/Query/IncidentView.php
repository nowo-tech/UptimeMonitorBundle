<?php

declare(strict_types=1);

namespace Nowo\UptimeMonitorBundle\Query;

use DateTimeImmutable;
use Nowo\UptimeMonitorBundle\Enum\CheckStatus;
use Nowo\UptimeMonitorBundle\Security\SecretRedactor;

/**
 * One incident of a monitor (since 1.36, {@see IncidentQueryInterface::list()}): a read model, never the entity. The
 * message has its URLs masked ({@see SecretRedactor::maskUrlsInText()}), also for rows written before that masking.
 */
final readonly class IncidentView
{
    /**
     * @param int $id Incident id
     * @param int $monitorId Monitor id
     * @param string $tenantSlug Tenant of the monitor (exact)
     * @param CheckStatus $triggerStatus Status that opened it (down or degraded)
     * @param DateTimeImmutable $startedAt When it started
     * @param DateTimeImmutable|null $endedAt When it was resolved (null: still open)
     * @param string|null $message Message of the check that opened it (URLs masked)
     */
    public function __construct(
        public int $id,
        public int $monitorId,
        public string $tenantSlug,
        public CheckStatus $triggerStatus,
        public DateTimeImmutable $startedAt,
        public ?DateTimeImmutable $endedAt,
        public ?string $message,
    ) {
    }

    /**
     * Whether the incident is still open.
     */
    public function isOpen(): bool
    {
        return !$this->endedAt instanceof DateTimeImmutable;
    }
}
