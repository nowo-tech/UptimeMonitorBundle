<?php

declare(strict_types=1);

namespace Nowo\UptimeMonitorBundle\Share;

use DateTimeImmutable;
use Nowo\UptimeMonitorBundle\Query\MonitorListCriteria;
use Nowo\UptimeMonitorBundle\Query\MonitorRef;

/**
 * Read model of one monitor share (since 1.35): plain values, never an entity.
 */
final readonly class MonitorShareView
{
    /**
     * @param int $id Share id
     * @param int $monitorId Shared monitor
     * @param string $tenantSlug Tenant of the monitor
     * @param string $userIdentifier The host's user identifier
     * @param DateTimeImmutable $createdAt When it was shared
     * @param bool $created {@see MonitorSharesInterface::create()} only: true when this call wrote the share, false when
     *                      it already existed (a repeated or concurrent request); false on every read
     */
    public function __construct(
        public int $id,
        public int $monitorId,
        public string $tenantSlug,
        public string $userIdentifier,
        public DateTimeImmutable $createdAt,
        public bool $created = false,
    ) {
    }

    /**
     * The monitor as a grant of {@see MonitorListCriteria} (shared monitors in a list).
     */
    public function monitorRef(): MonitorRef
    {
        return new MonitorRef($this->tenantSlug, $this->monitorId);
    }
}
