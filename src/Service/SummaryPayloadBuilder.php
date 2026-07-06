<?php

declare(strict_types=1);

namespace Nowo\UptimeMonitorBundle\Service;

use DateTimeImmutable;
use DateTimeInterface;
use Nowo\UptimeMonitorBundle\Entity\CheckResult;
use Nowo\UptimeMonitorBundle\Entity\Monitor;
use Nowo\UptimeMonitorBundle\Repository\CheckResultRepository;
use Nowo\UptimeMonitorBundle\Repository\MonitorRepository;

use function array_slice;
use function count;

/**
 * Builds dashboard summary payloads shared by the REST API and Mercure updates.
 */
final class SummaryPayloadBuilder
{
    public function __construct(
        private readonly MonitorRepository $monitorRepository,
        private readonly CheckResultRepository $checkResultRepository,
        private readonly UptimeMetricsService $metricsService,
        private readonly TenantDashboardSerializer $tenantSerializer,
    ) {
    }

    /**
     * @return array{
     *     tenant: string,
     *     since: string|null,
     *     server_time: string,
     *     monitors: list<array<string, mixed>>
     * }
     */
    public function buildTenantSummary(string $tenantSlug, ?DateTimeImmutable $since = null): array
    {
        $monitors = $this->monitorRepository->findByTenantSlug($tenantSlug);
        $ids      = array_values(array_filter(array_map(
            static fn (Monitor $m) => $m->getId(),
            $monitors,
        )));
        $now           = new DateTimeImmutable();
        $since24h      = $now->modify('-24 hours');
        $latestResults = $this->checkResultRepository->findLatestByMonitorIds($ids);
        $history24h    = $this->checkResultRepository->findRecentByMonitorIds(
            $ids,
            $since24h,
            UptimeMetricsService::HISTORY_BAR_MAX_CHECKS,
        );
        $checks24h  = $this->checkResultRepository->findChecksSinceByMonitorIds($ids, $since24h);
        $serverTime = $now->format(DateTimeInterface::ATOM);

        $items = [];
        foreach ($monitors as $monitor) {
            $monitorId = $monitor->getId();
            $latest    = $monitorId !== null ? ($latestResults[$monitorId] ?? null) : null;

            if ($since !== null && $latest !== null && $latest->getCheckedAt() <= $since) {
                continue;
            }

            $history = $monitorId !== null
                ? ($history24h[$monitorId] ?? [])
                : [];
            $checksFor24h = $monitorId !== null
                ? ($checks24h[$monitorId] ?? [])
                : [];

            $items[] = $this->buildMonitorSummaryItem(
                $monitor,
                $latest,
                $history,
                $checksFor24h,
            );
        }

        $payload = [
            'tenant'      => $tenantSlug,
            'since'       => $since?->format(DateTimeInterface::ATOM),
            'server_time' => $serverTime,
            'monitors'    => $items,
        ];

        if ($since === null) {
            $payload['stats']  = $this->tenantSerializer->buildQuickStats($monitors, $latestResults);
            $payload['events'] = array_map(
                fn (CheckResult $result): array => $this->tenantSerializer->serializeEvent($result),
                $this->checkResultRepository->findRecentForTenant($tenantSlug, 80),
            );
        }

        return $payload;
    }

    /**
     * @param list<CheckResult> $historyChecks chronological (oldest first)
     * @param list<CheckResult> $checks24h
     *
     * @return array<string, mixed>
     */
    public function buildMonitorSummaryItem(
        Monitor $monitor,
        ?CheckResult $latest = null,
        array $historyChecks = [],
        array $checks24h = [],
    ): array {
        if ($historyChecks === [] && $checks24h === []) {
            $since24h      = (new DateTimeImmutable())->modify('-24 hours');
            $historyChecks = $this->checkResultRepository->findRecentForMonitor(
                $monitor,
                $since24h,
                UptimeMetricsService::HISTORY_BAR_MAX_CHECKS,
            );
            $checks24h = $this->checkResultRepository->findChecksForMonitorSince($monitor, $since24h);
        }

        if ($latest === null) {
            $monitorId = $monitor->getId();
            if ($monitorId !== null) {
                $latest = $this->checkResultRepository->findLatestByMonitorIds([$monitorId])[$monitorId] ?? null;
            }
        }

        $historyChecks = $this->ensureLatestInHistory($historyChecks, $latest);

        return $this->serializeMonitor(
            $monitor,
            $latest,
            $this->metricsService->padHistorySegments(
                $this->metricsService->buildHistorySegments($historyChecks),
            ),
            $this->metricsService->uptimePercentFromChecks($checks24h),
        );
    }

    /**
     * @param list<CheckResult> $historyChecks
     *
     * @return list<CheckResult>
     */
    private function ensureLatestInHistory(array $historyChecks, ?CheckResult $latest): array
    {
        if ($latest === null) {
            return $historyChecks;
        }

        foreach ($historyChecks as $check) {
            if ($check->getId() !== null && $check->getId() === $latest->getId()) {
                return $historyChecks;
            }
        }

        $historyChecks[] = $latest;
        usort(
            $historyChecks,
            static fn (CheckResult $a, CheckResult $b): int => $a->getCheckedAt() <=> $b->getCheckedAt(),
        );

        $max = UptimeMetricsService::HISTORY_BAR_MAX_CHECKS;
        if (count($historyChecks) > $max) {
            $historyChecks = array_slice($historyChecks, -$max);
        }

        return $historyChecks;
    }

    /**
     * @return array{
     *     type: string,
     *     server_time: string,
     *     monitor: array<string, mixed>,
     *     event: array<string, mixed>,
     *     stats: array{up: int, down: int, degraded: int, unknown: int, paused: int}
     * }
     */
    public function buildMercureUpdatePayload(Monitor $monitor, CheckResult $result): array
    {
        $tenantSlug = $monitor->getTenant()->getSlug();
        $monitors   = $this->monitorRepository->findByTenantSlug($tenantSlug);
        $ids        = array_values(array_filter(array_map(
            static fn (Monitor $m) => $m->getId(),
            $monitors,
        )));
        $latestResults = $this->checkResultRepository->findLatestByMonitorIds($ids);

        return [
            'type'        => 'monitor_update',
            'server_time' => (new DateTimeImmutable())->format(DateTimeInterface::ATOM),
            'monitor'     => $this->buildMonitorSummaryItem($monitor, $result),
            'event'       => $this->tenantSerializer->serializeEvent($result),
            'stats'       => $this->tenantSerializer->buildQuickStats($monitors, $latestResults),
        ];
    }

    /**
     * @param list<array{status: string, checked_at: string}> $history
     */
    public function serializeMonitor(
        Monitor $monitor,
        ?CheckResult $latest,
        array $history = [],
        ?float $uptime24h = null,
    ): array {
        $monitorId = $monitor->getId();

        return [
            'id'               => $monitorId,
            'name'             => $monitor->getName(),
            'project'          => $monitor->getProject(),
            'parent_id'        => $monitor->getParent()?->getId(),
            'type'             => $monitor->getType()->value,
            'is_group'         => $monitor->isGroup(),
            'history'          => $history,
            'uptime_24h'       => $uptime24h,
            'target'           => $monitor->getTarget(),
            'paused'           => $monitor->isPaused(),
            'interval_seconds' => $monitor->getIntervalSeconds(),
            'next_check_at'    => $monitor->getNextCheckAt()?->format(DateTimeInterface::ATOM),
            'last_status'      => $latest?->getStatus()->value,
            'last_latency_ms'  => $latest?->getLatencyMs(),
            'last_checked_at'  => $latest?->getCheckedAt()?->format(DateTimeInterface::ATOM),
            'last_status_code' => $latest?->getStatusCode(),
        ];
    }
}
