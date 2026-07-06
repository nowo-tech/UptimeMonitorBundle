<?php

declare(strict_types=1);

namespace Nowo\UptimeMonitorBundle\Entity;

use DateTimeImmutable;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Nowo\UptimeMonitorBundle\Enum\AggregatePeriod;

#[ORM\Entity]
#[ORM\Table(name: 'uptime_check_aggregate')]
#[ORM\UniqueConstraint(name: 'uniq_uptime_aggregate_bucket', columns: ['monitor_id', 'period', 'period_start'])]
class CheckAggregate
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: Types::INTEGER)]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Monitor::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Monitor $monitor;

    #[ORM\Column(type: Types::STRING, length: 8, enumType: AggregatePeriod::class)]
    private AggregatePeriod $period;

    #[ORM\Column(name: 'period_start', type: Types::DATETIME_IMMUTABLE)]
    private DateTimeImmutable $periodStart;

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

    public function __construct(Monitor $monitor, AggregatePeriod $period, DateTimeImmutable $periodStart)
    {
        $this->monitor     = $monitor;
        $this->period      = $period;
        $this->periodStart = $periodStart;
    }

    public function getMonitor(): Monitor
    {
        return $this->monitor;
    }

    public function getPeriod(): AggregatePeriod
    {
        return $this->period;
    }

    public function getPeriodStart(): DateTimeImmutable
    {
        return $this->periodStart;
    }

    public function recordCheck(bool $isUp, int $latencyMs): void
    {
        ++$this->checksTotal;
        if ($isUp) {
            ++$this->checksUp;
        } else {
            ++$this->checksDown;
        }

        $this->recalculateMetrics($latencyMs);
    }

    public function applyTotals(int $checksTotal, int $checksUp, int $latencyAvgMs): void
    {
        $this->checksTotal  = $checksTotal;
        $this->checksUp     = $checksUp;
        $this->checksDown   = $checksTotal - $checksUp;
        $this->latencyAvgMs = $latencyAvgMs;
        $this->uptimeRatio  = $checksTotal > 0
            ? round($checksUp / $checksTotal, 4)
            : 0.0;
    }

    public function getChecksTotal(): int
    {
        return $this->checksTotal;
    }

    public function getChecksUp(): int
    {
        return $this->checksUp;
    }

    public function getChecksDown(): int
    {
        return $this->checksDown;
    }

    public function getUptimeRatio(): float
    {
        return $this->uptimeRatio;
    }

    public function getLatencyAvgMs(): int
    {
        return $this->latencyAvgMs;
    }

    private function recalculateMetrics(int $latencyMs): void
    {
        $this->uptimeRatio = $this->checksTotal > 0
            ? round($this->checksUp / $this->checksTotal, 4)
            : 0.0;

        $this->latencyAvgMs = (int) round(
            (($this->latencyAvgMs * ($this->checksTotal - 1)) + $latencyMs) / $this->checksTotal,
        );
    }
}
