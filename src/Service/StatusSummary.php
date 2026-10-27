<?php

declare(strict_types=1);

namespace Nowo\UptimeMonitorBundle\Service;

use Nowo\UptimeMonitorBundle\Entity\CheckResult;
use Nowo\UptimeMonitorBundle\Entity\Monitor;
use Nowo\UptimeMonitorBundle\Enum\CheckStatus;
use Nowo\UptimeMonitorBundle\Repository\CheckResultRepository;
use Nowo\UptimeMonitorBundle\Repository\MonitorRepository;

use function array_filter;
use function array_map;
use function array_values;

/**
 * The status of a tenant at a glance (since 1.13): its active monitors (groups and paused ones left out) with their
 * latest check, the count per status and the overall status (the worst one decides).
 */
final readonly class StatusSummary
{
    /** Statuses counted, worst first. */
    public const STATUSES = ['down', 'degraded', 'unknown', 'up'];

    /**
     * Creates the summary builder.
     *
     * @param MonitorRepository $monitors Monitor repository
     * @param CheckResultRepository $checks Check result repository
     */
    public function __construct(
        private MonitorRepository $monitors,
        private CheckResultRepository $checks,
    ) {
    }

    /**
     * The tenant's active monitors and their latest check.
     *
     * @return list<array{monitor: Monitor, latest: CheckResult|null}>
     */
    public function rows(string $tenantSlug): array
    {
        $monitors = array_values(array_filter(
            $this->monitors->findByTenantSlug($tenantSlug),
            static fn (Monitor $monitor): bool => !$monitor->isPaused() && !$monitor->isGroup(),
        ));
        $latest = $this->checks->findLatestByMonitorIds(array_values(array_filter(array_map(
            static fn (Monitor $monitor): ?int => $monitor->getId(),
            $monitors,
        ))));

        return array_map(
            static fn (Monitor $monitor): array => ['monitor' => $monitor, 'latest' => $latest[(int) $monitor->getId()] ?? null],
            $monitors,
        );
    }

    /**
     * Monitors per status (`down`, `degraded`, `unknown` — not checked yet —, `up`).
     *
     * @param list<array{monitor: Monitor, latest: CheckResult|null}> $rows
     *
     * @return array{down: int, degraded: int, unknown: int, up: int}
     */
    public static function counts(array $rows): array
    {
        $counts = ['down' => 0, 'degraded' => 0, 'unknown' => 0, 'up' => 0];
        foreach ($rows as $row) {
            ++$counts[($row['latest']?->getStatus() ?? CheckStatus::Unknown)->value];
        }

        return $counts;
    }

    /**
     * Overall status: `major` when a monitor is down, else `degraded`, else `unknown` when one is not checked yet (or
     * there is none), else `operational`.
     *
     * @param list<array{monitor: Monitor, latest: CheckResult|null}> $rows
     */
    public static function overall(array $rows): string
    {
        $counts = self::counts($rows);

        return match (true) {
            $rows === []            => 'unknown',
            $counts['down'] > 0     => 'major',
            $counts['degraded'] > 0 => 'degraded',
            $counts['unknown'] > 0  => 'unknown',
            default                 => 'operational',
        };
    }
}
