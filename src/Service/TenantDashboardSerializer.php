<?php

declare(strict_types=1);

namespace Nowo\UptimeMonitorBundle\Service;

use DateTimeInterface;
use Nowo\UptimeMonitorBundle\Entity\CheckResult;
use Nowo\UptimeMonitorBundle\Entity\Monitor;
use Nowo\UptimeMonitorBundle\Enum\CheckStatus;

/**
 * Serializes tenant-wide dashboard stats and event log rows.
 */
final class TenantDashboardSerializer
{
    /**
     * @param list<Monitor> $monitors
     * @param array<int, CheckResult> $latestByMonitorId
     *
     * @return array{up: int, down: int, degraded: int, unknown: int, paused: int}
     */
    public function buildQuickStats(array $monitors, array $latestByMonitorId): array
    {
        $stats = [
            'up'       => 0,
            'down'     => 0,
            'degraded' => 0,
            'unknown'  => 0,
            'paused'   => 0,
        ];

        foreach ($monitors as $monitor) {
            if ($monitor->isPaused()) {
                ++$stats['paused'];
                continue;
            }

            $monitorId = $monitor->getId();
            $latest    = $monitorId !== null ? ($latestByMonitorId[$monitorId] ?? null) : null;
            $status    = $latest?->getStatus() ?? CheckStatus::Unknown;

            match ($status) {
                CheckStatus::Up       => ++$stats['up'],
                CheckStatus::Down     => ++$stats['down'],
                CheckStatus::Degraded => ++$stats['degraded'],
                CheckStatus::Unknown  => ++$stats['unknown'],
            };
        }

        return $stats;
    }

    /**
     * @return array{
     *     monitor_id: int|null,
     *     monitor_name: string,
     *     status: string,
     *     checked_at: string,
     *     message: string|null,
     *     status_code: int|null,
     *     is_group: bool
     * }
     */
    public function serializeEvent(CheckResult $result): array
    {
        $monitor = $result->getMonitor();

        return [
            'monitor_id'   => $monitor->getId(),
            'monitor_name' => $monitor->getName(),
            'status'       => $result->getStatus()->value,
            'checked_at'   => $result->getCheckedAt()->format(DateTimeInterface::ATOM),
            'message'      => $result->getMessage(),
            'status_code'  => $result->getStatusCode(),
            'is_group'     => $monitor->isGroup(),
        ];
    }
}
