<?php

declare(strict_types=1);

namespace Nowo\UptimeMonitorBundle\Service;

use DateTimeImmutable;
use Nowo\UptimeMonitorBundle\Entity\CheckAggregate;
use Nowo\UptimeMonitorBundle\Entity\Monitor;
use Nowo\UptimeMonitorBundle\Enum\AggregatePeriod;
use Nowo\UptimeMonitorBundle\Repository\CheckAggregateRepository;

use function sprintf;

/**
 * Builds Chart.js-friendly datasets from stored aggregates.
 */
final class AggregateChartService
{
    public function __construct(
        private readonly CheckAggregateRepository $aggregateRepository,
    ) {
    }

    /**
     * @return array{
     *     labels: list<string>,
     *     uptime_percent: list<float>,
     *     latency_avg_ms: list<int>,
     *     checks_total: list<int>
     * }
     */
    public function buildMonitorSeries(
        Monitor $monitor,
        AggregatePeriod $period,
        int $days = 30,
    ): array {
        $to   = new DateTimeImmutable();
        $from = $to->modify(sprintf('-%d days', max(1, $days)));

        $aggregates = $this->aggregateRepository->findForMonitorInRange($monitor, $period, $from, $to);

        return $this->serializeAggregates($aggregates, $period);
    }

    /**
     * @return array<string, array{name: string, labels: list<string>, uptime_percent: list<float>}>
     */
    public function buildTenantOverview(string $tenantSlug, int $days = 7): array
    {
        $to     = new DateTimeImmutable();
        $from   = $to->modify(sprintf('-%d days', max(1, $days)));
        $period = AggregatePeriod::Day;
        $rows   = $this->aggregateRepository->findTenantOverview($tenantSlug, $period, $from, $to);

        $byMonitor = [];
        foreach ($rows as $aggregate) {
            $monitorId = $aggregate->getMonitor()->getId() ?? 0;
            $key       = (string) $monitorId;
            if (!isset($byMonitor[$key])) {
                $byMonitor[$key] = [
                    'name'   => $aggregate->getMonitor()->getName(),
                    'points' => [],
                ];
            }
            $byMonitor[$key]['points'][] = $aggregate;
        }

        $result = [];
        foreach ($byMonitor as $key => $data) {
            $series       = $this->serializeAggregates($data['points'], $period);
            $result[$key] = [
                'name'           => $data['name'],
                'labels'         => $series['labels'],
                'uptime_percent' => $series['uptime_percent'],
            ];
        }

        /* @var array<string, array{name: string, labels: list<string>, uptime_percent: list<float>}> $result */
        return $result;
    }

    /**
     * @param list<CheckAggregate> $aggregates
     *
     * @return array{
     *     labels: list<string>,
     *     uptime_percent: list<float>,
     *     latency_avg_ms: list<int>,
     *     checks_total: list<int>
     * }
     */
    private function serializeAggregates(array $aggregates, AggregatePeriod $period): array
    {
        $labels        = [];
        $uptimePercent = [];
        $latencyAvgMs  = [];
        $checksTotal   = [];

        foreach ($aggregates as $aggregate) {
            $labels[]        = $this->formatLabel($aggregate->getPeriodStart(), $period);
            $uptimePercent[] = round($aggregate->getUptimeRatio() * 100, 2);
            $latencyAvgMs[]  = $aggregate->getLatencyAvgMs();
            $checksTotal[]   = $aggregate->getChecksTotal();
        }

        return [
            'labels'         => $labels,
            'uptime_percent' => $uptimePercent,
            'latency_avg_ms' => $latencyAvgMs,
            'checks_total'   => $checksTotal,
        ];
    }

    private function formatLabel(DateTimeImmutable $at, AggregatePeriod $period): string
    {
        return match ($period) {
            AggregatePeriod::Hour  => $at->format('m-d H:i'),
            AggregatePeriod::Day   => $at->format('Y-m-d'),
            AggregatePeriod::Month => $at->format('Y-m'),
            AggregatePeriod::Year  => $at->format('Y'),
        };
    }
}
