<?php

declare(strict_types=1);

namespace Nowo\UptimeMonitorBundle\Service;

use DateTimeInterface;
use Nowo\UptimeMonitorBundle\Entity\CheckAggregate;
use Nowo\UptimeMonitorBundle\Entity\CheckResult;
use Nowo\UptimeMonitorBundle\Enum\CheckStatus;

use function count;

/**
 * Computes uptime percentages and history bar segments from checks and aggregates.
 */
final class UptimeMetricsService
{
    public const HISTORY_BAR_MAX_CHECKS = 50;

    /**
     * @param list<CheckResult> $checks chronological (oldest first)
     *
     * @return list<array{status: string, checked_at: string}>
     */
    public function buildHistorySegments(array $checks): array
    {
        $segments = [];
        foreach ($checks as $check) {
            $segments[] = [
                'status'     => $check->getStatus()->value,
                'checked_at' => $check->getCheckedAt()->format(DateTimeInterface::ATOM),
            ];
        }

        return $segments;
    }

    /**
     * Fixed-slot history bar (Kuma style): grey empty slots on the left, checks aligned to the right.
     *
     * @param list<CheckResult> $checks chronological (oldest first)
     *
     * @return list<array{status: string, checked_at: string}>
     */
    public function buildPaddedHistoryBar(array $checks, int $slots = self::HISTORY_BAR_MAX_CHECKS): array
    {
        $slots     = max(1, $slots);
        $filled    = $this->buildHistorySegments($checks);
        $padded    = [];
        $emptySlot = ['status' => 'empty', 'checked_at' => ''];

        for ($i = 0; $i < $slots; ++$i) {
            $padded[] = $emptySlot;
        }

        $count = min(count($filled), $slots);
        for ($i = 0; $i < $count; ++$i) {
            $padded[$slots - $count + $i] = $filled[count($filled) - $count + $i];
        }

        return $padded;
    }

    /**
     * @param list<array{status: string, checked_at: string}> $segments
     *
     * @return list<array{status: string, checked_at: string}>
     */
    public function padHistorySegments(array $segments, int $slots = self::HISTORY_BAR_MAX_CHECKS): array
    {
        $slots     = max(1, $slots);
        $padded    = [];
        $emptySlot = ['status' => 'empty', 'checked_at' => ''];

        for ($i = 0; $i < $slots; ++$i) {
            $padded[] = $emptySlot;
        }

        $count = min(count($segments), $slots);
        for ($i = 0; $i < $count; ++$i) {
            $padded[$slots - $count + $i] = $segments[count($segments) - $count + $i];
        }

        return $padded;
    }

    /**
     * @param list<CheckResult> $checks
     */
    public function uptimePercentFromChecks(array $checks): ?float
    {
        if ($checks === []) {
            return null;
        }

        $up = 0;
        foreach ($checks as $check) {
            if ($check->getStatus() === CheckStatus::Up) {
                ++$up;
            }
        }

        return round(($up / count($checks)) * 100, 1);
    }

    /**
     * @param list<CheckAggregate> $aggregates
     */
    public function uptimePercentFromAggregates(array $aggregates): ?float
    {
        $total = 0;
        $up    = 0;

        foreach ($aggregates as $aggregate) {
            $total += $aggregate->getChecksTotal();
            $up += $aggregate->getChecksUp();
        }

        if ($total === 0) {
            return null;
        }

        return round(($up / $total) * 100, 1);
    }
}
