<?php

declare(strict_types=1);

namespace Nowo\UptimeMonitorBundle\Service;

use DateTimeImmutable;
use DateTimeInterface;
use Nowo\UptimeMonitorBundle\Entity\CheckResult;
use Nowo\UptimeMonitorBundle\Entity\Monitor;
use Nowo\UptimeMonitorBundle\Enum\AggregatePeriod;
use Nowo\UptimeMonitorBundle\Enum\MonitorType;
use Nowo\UptimeMonitorBundle\Repository\CheckAggregateRepository;
use Nowo\UptimeMonitorBundle\Repository\CheckResultRepository;
use Nowo\UptimeMonitorBundle\Repository\MonitorRepository;

/**
 * Builds grouped dashboard rows with history bars and uptime metrics.
 */
final class DashboardViewBuilder
{
    public function __construct(
        private readonly MonitorRepository $monitorRepository,
        private readonly CheckResultRepository $checkResultRepository,
        private readonly CheckAggregateRepository $aggregateRepository,
        private readonly UptimeMetricsService $metricsService,
        private readonly TenantDashboardSerializer $tenantSerializer,
    ) {
    }

    /**
     * @return array{up: int, down: int, degraded: int, unknown: int, paused: int}
     */
    public function buildQuickStats(string $tenantSlug): array
    {
        $monitors = $this->monitorRepository->findByTenantSlug($tenantSlug);
        $ids      = array_values(array_filter(array_map(
            static fn (Monitor $m) => $m->getId(),
            $monitors,
        )));

        return $this->tenantSerializer->buildQuickStats(
            $monitors,
            $this->checkResultRepository->findLatestByMonitorIds($ids),
        );
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function buildRecentEvents(string $tenantSlug, ?int $monitorId = null, int $limit = 80): array
    {
        if ($monitorId !== null) {
            $monitor = $this->monitorRepository->find($monitorId);
            if ($monitor === null || $monitor->getTenant()->getSlug() !== $tenantSlug) {
                return $this->buildRecentEvents($tenantSlug, null, $limit);
            }

            $results = $this->checkResultRepository->findRecentForMonitor($monitor, null, $limit);

            return array_map(
                fn (CheckResult $result): array => $this->tenantSerializer->serializeEvent($result),
                array_reverse($results),
            );
        }

        return array_map(
            fn (CheckResult $result): array => $this->tenantSerializer->serializeEvent($result),
            $this->checkResultRepository->findRecentForTenant($tenantSlug, $limit),
        );
    }

    /**
     * @return list<array{
     *     label: string,
     *     group_monitor: Monitor|null,
     *     group_row: array<string, mixed>|null,
     *     children: list<array<string, mixed>>
     * }>
     */
    public function buildProjectGroups(string $tenantSlug): array
    {
        $monitors = $this->monitorRepository->findByTenantSlug($tenantSlug);
        $ids      = array_values(array_filter(array_map(
            static fn (Monitor $m) => $m->getId(),
            $monitors,
        )));

        $now           = new DateTimeImmutable();
        $since24h      = $now->modify('-24 hours');
        $since30d      = $now->modify('-30 days');
        $latestResults = $this->checkResultRepository->findLatestByMonitorIds($ids);
        $history24h    = $this->checkResultRepository->findRecentByMonitorIds(
            $ids,
            $since24h,
            UptimeMetricsService::HISTORY_BAR_MAX_CHECKS,
        );
        $checks24h     = $this->checkResultRepository->findChecksSinceByMonitorIds($ids, $since24h);
        $aggregates30d = $this->aggregateRepository->findDailyAggregatesByMonitorIds($ids, $since30d, $now);

        $rowBuilder = function (Monitor $monitor) use (
            $latestResults,
            $history24h,
            $checks24h,
            $aggregates30d,
        ): array {
            $monitorId = $monitor->getId();

            return [
                'monitor' => $monitor,
                'latest'  => $monitorId !== null ? ($latestResults[$monitorId] ?? null) : null,
                'history' => $this->metricsService->buildPaddedHistoryBar(
                    $monitorId !== null ? ($history24h[$monitorId] ?? []) : [],
                ),
                'uptime_24h' => $this->metricsService->uptimePercentFromChecks(
                    $monitorId !== null ? ($checks24h[$monitorId] ?? []) : [],
                ),
                'uptime_30d' => $this->metricsService->uptimePercentFromAggregates(
                    $monitorId !== null ? ($aggregates30d[$monitorId] ?? []) : [],
                ),
                'nested' => false,
            ];
        };

        $assignedIds = [];
        $sections    = [];

        foreach ($this->monitorRepository->findGroupsByTenantSlug($tenantSlug) as $group) {
            $groupId = $group->getId();
            if ($groupId === null) {
                continue;
            }

            $groupRow = $rowBuilder($group);
            $children = [];

            foreach ($this->monitorRepository->findChildrenOf($groupId) as $child) {
                $childId = $child->getId();
                if ($childId !== null) {
                    $assignedIds[$childId] = true;
                }
                $childRow           = $rowBuilder($child);
                $childRow['nested'] = true;
                $children[]         = $childRow;
            }

            $sections[] = [
                'label'         => $group->getName(),
                'group_monitor' => $group,
                'group_row'     => $groupRow,
                'children'      => $children,
            ];
        }

        $legacyByProject = [];
        foreach ($monitors as $monitor) {
            if ($monitor->getType() === MonitorType::Group || $monitor->getParent() !== null) {
                continue;
            }

            $monitorId = $monitor->getId();
            if ($monitorId !== null && isset($assignedIds[$monitorId])) {
                continue;
            }

            $project                     = $monitor->getProject() ?? '';
            $legacyByProject[$project][] = $rowBuilder($monitor);
        }

        ksort($legacyByProject);
        foreach ($legacyByProject as $project => $rows) {
            $sections[] = [
                'label'         => $project !== '' ? $project : 'General',
                'group_monitor' => null,
                'group_row'     => null,
                'children'      => $rows,
            ];
        }

        return $sections;
    }

    /**
     * @return array{
     *     history: list<array{status: string, checked_at: string}>,
     *     uptime_24h: float|null,
     *     uptime_30d: float|null,
     *     events: list<array{status: string, checked_at: string, latency_ms: int, status_code: int|null, message: string|null}>,
     *     latency_series: array{labels: list<string>, latency_ms: list<int>},
     *     children: list<array{monitor: Monitor, latest: CheckResult|null}>
     * }
     */
    public function buildMonitorDetail(Monitor $monitor): array
    {
        $now      = new DateTimeImmutable();
        $since24h = $now->modify('-24 hours');
        $since30d = $now->modify('-30 days');

        $historyChecks = $this->checkResultRepository->findRecentForMonitor(
            $monitor,
            $since24h,
            UptimeMetricsService::HISTORY_BAR_MAX_CHECKS,
        );
        $checks24h     = $this->checkResultRepository->findChecksForMonitorSince($monitor, $since24h);
        $events        = $this->checkResultRepository->findRecentForMonitor($monitor, null, 100);
        $aggregates30d = $this->aggregateRepository->findForMonitorInRange(
            $monitor,
            AggregatePeriod::Day,
            $since30d,
            $now,
        );
        $hourly24h = $this->aggregateRepository->findForMonitorInRange(
            $monitor,
            AggregatePeriod::Hour,
            $since24h,
            $now,
        );

        $latencyLabels = [];
        $latencyMs     = [];
        foreach ($hourly24h as $aggregate) {
            if ($aggregate->getChecksTotal() === 0) {
                continue;
            }
            $latencyLabels[] = $aggregate->getPeriodStart()->format('H:i');
            $latencyMs[]     = $aggregate->getLatencyAvgMs();
        }

        if ($latencyLabels === [] && $historyChecks !== []) {
            foreach ($historyChecks as $check) {
                $latencyLabels[] = $check->getCheckedAt()->format('H:i');
                $latencyMs[]     = $check->getLatencyMs();
            }
        }

        $children = [];
        if ($monitor->isGroup() && $monitor->getId() !== null) {
            $childIds = array_values(array_filter(array_map(
                static fn (Monitor $m) => $m->getId(),
                $this->monitorRepository->findChildrenOf($monitor->getId()),
            )));
            $latestChildren = $this->checkResultRepository->findLatestByMonitorIds($childIds);
            foreach ($this->monitorRepository->findChildrenOf($monitor->getId()) as $child) {
                $childId    = $child->getId();
                $children[] = [
                    'monitor' => $child,
                    'latest'  => $childId !== null ? ($latestChildren[$childId] ?? null) : null,
                ];
            }
        }

        return [
            'history'    => $this->metricsService->buildPaddedHistoryBar($historyChecks),
            'uptime_24h' => $this->metricsService->uptimePercentFromChecks($checks24h),
            'uptime_30d' => $this->metricsService->uptimePercentFromAggregates($aggregates30d),
            'events'     => array_map(
                static fn (CheckResult $check): array => [
                    'status'      => $check->getStatus()->value,
                    'checked_at'  => $check->getCheckedAt()->format(DateTimeInterface::ATOM),
                    'latency_ms'  => $check->getLatencyMs(),
                    'status_code' => $check->getStatusCode(),
                    'message'     => $check->getMessage(),
                ],
                $events,
            ),
            'latency_series' => [
                'labels'     => $latencyLabels,
                'latency_ms' => $latencyMs,
            ],
            'children' => $children,
        ];
    }
}
