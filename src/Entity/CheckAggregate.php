<?php

declare(strict_types=1);

namespace Nowo\UptimeMonitorBundle\Entity;

use DateTimeImmutable;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Nowo\UptimeMonitorBundle\Enum\AggregatePeriod;

/**
 * Rolled-up check counts and latency of one monitor for one period bucket.
 */
#[ORM\Entity]
#[ORM\Table(name: 'uptime_check_aggregate')]
#[ORM\UniqueConstraint(name: 'uniq_uptime_aggregate_bucket', columns: ['monitor_id', 'period', 'period_start'])]
class CheckAggregate
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: Types::INTEGER)]
    /** @phpstan-ignore property.unusedType (Doctrine sets id via reflection) */
    private ?int $id = null;

    #[ORM\Column(type: Types::INTEGER)]
    private int $checksTotal = 0;

    #[ORM\Column(type: Types::INTEGER)]
    private int $checksUp = 0;

    #[ORM\Column(type: Types::INTEGER)]
    private int $checksDown = 0;

    #[ORM\Column(type: Types::FLOAT)]
    private float $uptimeRatio = 0.0;

    #[ORM\Column(type: Types::INTEGER)]
    private int $latencyAvgMs = 0;

    /**
     * Creates an empty bucket of a monitor.
     *
     * @param DateTimeImmutable $periodStart Start of the bucket
     */
    public function __construct(
        #[ORM\ManyToOne(targetEntity: Monitor::class)]
        #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
        private Monitor $monitor,
        #[ORM\Column(type: Types::STRING, length: 8, enumType: AggregatePeriod::class)]
        private AggregatePeriod $period,
        #[ORM\Column(name: 'period_start', type: Types::DATETIME_IMMUTABLE)]
        private DateTimeImmutable $periodStart
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
     * Returns the monitor of the bucket.
     */
    public function getMonitor(): Monitor
    {
        return $this->monitor;
    }

    /**
     * Returns the bucket size.
     */
    public function getPeriod(): AggregatePeriod
    {
        return $this->period;
    }

    /**
     * Returns the start of the bucket.
     */
    public function getPeriodStart(): DateTimeImmutable
    {
        return $this->periodStart;
    }

    /**
     * Adds one check to the bucket and updates the uptime ratio and the average latency.
     */
    public function recordCheck(bool $isUp, int $latencyMs): void
    {
        // @igor-ignore - Doctrine entity field; instance-scoped, not a shared service.
        ++$this->checksTotal;
        if ($isUp) {
            // @igor-ignore - Doctrine entity field; instance-scoped, not a shared service.
            ++$this->checksUp;
        } else {
            // @igor-ignore - Doctrine entity field; instance-scoped, not a shared service.
            ++$this->checksDown;
        }

        $this->recalculateMetrics($latencyMs);
    }

    /**
     * Replaces the bucket totals (rollup rebuild).
     */
    public function applyTotals(int $checksTotal, int $checksUp, int $latencyAvgMs): void
    {
        // @igor-ignore - Doctrine entity field; instance-scoped, not a shared service.
        $this->checksTotal = $checksTotal;
        // @igor-ignore - Doctrine entity field; instance-scoped, not a shared service.
        $this->checksUp = $checksUp;
        // @igor-ignore - Doctrine entity field; instance-scoped, not a shared service.
        $this->checksDown = $checksTotal - $checksUp;
        // @igor-ignore - Doctrine entity field; instance-scoped, not a shared service.
        $this->latencyAvgMs = $latencyAvgMs;
        // @igor-ignore - Doctrine entity field; instance-scoped, not a shared service.
        $this->uptimeRatio = $checksTotal > 0
            ? round($checksUp / $checksTotal, 4)
            : 0.0;
    }

    /**
     * Returns the number of checks in the bucket.
     */
    public function getChecksTotal(): int
    {
        return $this->checksTotal;
    }

    /**
     * Returns the number of up checks in the bucket.
     */
    public function getChecksUp(): int
    {
        return $this->checksUp;
    }

    /**
     * Returns the number of non-up checks in the bucket.
     */
    public function getChecksDown(): int
    {
        return $this->checksDown;
    }

    /**
     * Returns the up ratio (0..1, four decimals).
     */
    public function getUptimeRatio(): float
    {
        return $this->uptimeRatio;
    }

    /**
     * Returns the average latency in milliseconds.
     */
    public function getLatencyAvgMs(): int
    {
        return $this->latencyAvgMs;
    }

    private function recalculateMetrics(int $latencyMs): void
    {
        // @igor-ignore - Doctrine entity field; instance-scoped, not a shared service.
        // Only called right after recordCheck() incremented checksTotal, so it is never zero here.
        $this->uptimeRatio = round($this->checksUp / $this->checksTotal, 4);

        // @igor-ignore - Doctrine entity field; instance-scoped, not a shared service.
        $this->latencyAvgMs = (int) round(
            (($this->latencyAvgMs * ($this->checksTotal - 1)) + $latencyMs) / $this->checksTotal,
        );
    }
}
