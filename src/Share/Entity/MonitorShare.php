<?php

declare(strict_types=1);

namespace Nowo\UptimeMonitorBundle\Share\Entity;

use DateTimeImmutable;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Nowo\UptimeMonitorBundle\Entity\Monitor;
use Nowo\UptimeMonitorBundle\Share\MonitorShares;

/**
 * A monitor shared with one user of the host (since 1.35, opt-in with `shares.enabled`): the monitor and the host's user
 * identifier, one row per pair (unique). Written and read only through {@see MonitorShares}; gone with its monitor
 * (`ON DELETE CASCADE`). The user identifier is the host's own id (a UUID, a numeric id as a string, a username...),
 * compared exactly (binary NO PAD collation on MySQL / MariaDB, plus a PHP re-check).
 *
 * Outside the `Entity` namespace on purpose: it is mapped only when shares are enabled, so hosts that do not use shares
 * get no new table.
 */
#[ORM\Entity]
#[ORM\Table(name: 'uptime_monitor_share')]
#[ORM\UniqueConstraint(name: 'uniq_uptime_monitor_share_pair', columns: ['monitor_id', 'user_identifier'])]
#[ORM\Index(name: 'idx_uptime_monitor_share_user', columns: ['user_identifier'])]
class MonitorShare
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: Types::INTEGER)]
    /** @phpstan-ignore property.unusedType (Doctrine sets id via reflection) */
    private ?int $id = null;

    /**
     * Creates a share.
     *
     * @param Monitor $monitor The monitor (a reference by id is enough)
     * @param string $userIdentifier The host's user identifier (1 to 128 characters)
     * @param DateTimeImmutable $createdAt When it was shared
     */
    public function __construct(
        #[ORM\ManyToOne(targetEntity: Monitor::class)]
        #[ORM\JoinColumn(name: 'monitor_id', nullable: false, onDelete: 'CASCADE')]
        private Monitor $monitor,
        #[ORM\Column(name: 'user_identifier', type: Types::STRING, length: 128)]
        private string $userIdentifier,
        #[ORM\Column(name: 'created_at', type: Types::DATETIME_IMMUTABLE)]
        private DateTimeImmutable $createdAt,
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
     * Returns the shared monitor.
     */
    public function getMonitor(): Monitor
    {
        return $this->monitor;
    }

    /**
     * Returns the host's user identifier.
     */
    public function getUserIdentifier(): string
    {
        return $this->userIdentifier;
    }

    /**
     * Returns when the monitor was shared.
     */
    public function getCreatedAt(): DateTimeImmutable
    {
        return $this->createdAt;
    }
}
