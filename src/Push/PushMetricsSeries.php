<?php

declare(strict_types=1);

namespace Nowo\UptimeMonitorBundle\Push;

use DateTimeImmutable;
use Nowo\UptimeMonitorBundle\Entity\Monitor;
use Nowo\UptimeMonitorBundle\Repository\CheckResultRepository;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\Clock\NativeClock;

use function array_keys;
use function count;
use function in_array;
use function intdiv;
use function is_array;
use function is_float;
use function is_int;
use function is_string;
use function max;
use function round;
use function sprintf;

/**
 * The server health view of a push monitor (since 1.14): the last sample with each metric's level against the
 * thresholds, and the series of the stored checks for the charts (CPU, memory, swap, load per core, each disk).
 */
final readonly class PushMetricsSeries
{
    /** Ranges of the view, in hours. */
    public const RANGES = [1, 6, 24, 168];

    /** Most points per series; longer ranges are thinned evenly. */
    public const MAX_POINTS = 240;

    /**
     * @param CheckResultRepository $checkResultRepository Reads the stored checks of the range
     * @param ClockInterface $clock Reference clock of the range
     */
    public function __construct(
        private CheckResultRepository $checkResultRepository,
        private ClockInterface $clock = new NativeClock(),
    ) {
    }

    /**
     * Series of the last `hours` hours (an unknown range falls back to 24 h).
     *
     * @return array{hours: int, labels: list<string>, cpu: list<float|null>, memory: list<float|null>, swap: list<float|null>, load: list<float|null>, disks: array<string, list<float|null>>}
     */
    public function series(Monitor $monitor, int $hours = 24): array
    {
        $hours   = in_array($hours, self::RANGES, true) ? $hours : 24;
        $since   = $this->clock->now()->modify(sprintf('-%d hours', $hours));
        $samples = [];
        foreach ($this->checkResultRepository->findChecksForMonitorSince($monitor, $since) as $result) {
            $metadata = $result->getMetadata();
            if (is_array($metadata) && $metadata !== []) {
                $samples[] = [$result->getCheckedAt(), $metadata];
            }
        }
        $step   = max(1, intdiv(count($samples) + self::MAX_POINTS - 1, self::MAX_POINTS));
        $series = ['hours' => $hours, 'labels' => [], 'cpu' => [], 'memory' => [], 'swap' => [], 'load' => [], 'disks' => []];
        $mounts = [];
        foreach ($samples as [, $sample]) {
            $mounts += self::disks($sample);
        }
        foreach ($samples as $i => [$at, $sample]) {
            if ($i % $step !== 0) {
                continue;
            }
            $series['labels'][] = self::label($at, $hours);
            $series['cpu'][]    = self::number($sample['cpu_pct'] ?? null);
            $series['memory'][] = self::number($sample['mem_pct'] ?? null);
            $series['swap'][]   = self::number($sample['swap_pct'] ?? null);
            $series['load'][]   = self::loadPerCore($sample);
            $disks              = self::disks($sample);
            foreach (array_keys($mounts) as $mount) {
                $series['disks'][$mount][] = $disks[$mount] ?? null;
            }
        }

        return $series;
    }

    /**
     * The last sample as gauges: value and level (`ok`, `warn`, `critical`) of each metric and disk.
     *
     * @return array{metrics: list<array{key: string, value: float, level: string}>, disks: list<array{mount: string, used: float|null, inodes: float|null, level: string}>, uptime_s: int|null, cpu_count: int|null}|null
     */
    public function gauges(Monitor $monitor): ?array
    {
        $sample = $monitor->getPushSample();
        if ($sample === null) {
            return null;
        }
        $limits  = PushThresholds::of($monitor)->limits;
        $metrics = [];
        foreach (['cpu' => self::number($sample['cpu_pct'] ?? null), 'memory' => self::number($sample['mem_pct'] ?? null), 'swap' => self::number($sample['swap_pct'] ?? null), 'load' => self::loadPerCore($sample)] as $key => $value) {
            if ($value !== null) {
                $metrics[] = ['key' => $key, 'value' => $value, 'level' => self::level($value, $limits[$key])];
            }
        }
        $disks = [];
        foreach (is_array($sample['disks'] ?? null) ? $sample['disks'] : [] as $disk) {
            if (!is_array($disk) || !is_string($disk['mount'] ?? null)) {
                continue;
            }
            $used    = self::number($disk['used_pct'] ?? null);
            $inodes  = self::number($disk['inodes_pct'] ?? null);
            $levels  = [$used === null ? 'ok' : self::level($used, $limits['disk']), $inodes === null ? 'ok' : self::level($inodes, $limits['inodes'])];
            $disks[] = ['mount' => $disk['mount'], 'used' => $used, 'inodes' => $inodes, 'level' => in_array('critical', $levels, true) ? 'critical' : (in_array('warn', $levels, true) ? 'warn' : 'ok')];
        }

        return [
            'metrics'   => $metrics,
            'disks'     => $disks,
            'uptime_s'  => is_int($sample['uptime_s'] ?? null) ? $sample['uptime_s'] : null,
            'cpu_count' => is_int($sample['cpu_count'] ?? null) ? $sample['cpu_count'] : null,
        ];
    }

    /**
     * @param array{0: float|null, 1: float|null} $limits
     */
    private static function level(float $value, array $limits): string
    {
        return match (true) {
            $limits[1] !== null && $value >= $limits[1] => 'critical',
            $limits[0] !== null && $value >= $limits[0] => 'warn',
            default                                     => 'ok',
        };
    }

    /**
     * @param array<mixed> $sample
     *
     * @return array<string, float|null> mount => used %
     */
    private static function disks(array $sample): array
    {
        $disks = [];
        foreach (is_array($sample['disks'] ?? null) ? $sample['disks'] : [] as $disk) {
            if (is_array($disk) && is_string($disk['mount'] ?? null)) {
                $disks[$disk['mount']] = self::number($disk['used_pct'] ?? null);
            }
        }

        return $disks;
    }

    /**
     * @param array<mixed> $sample
     */
    private static function loadPerCore(array $sample): ?float
    {
        $load  = self::number($sample['load5'] ?? $sample['load1'] ?? null);
        $cores = $sample['cpu_count'] ?? 1;

        return $load === null ? null : round($load / max(1, is_int($cores) ? $cores : 1), 2);
    }

    private static function number(mixed $value): ?float
    {
        return is_int($value) || is_float($value) ? (float) $value : null;
    }

    private static function label(DateTimeImmutable $at, int $hours): string
    {
        return $at->format($hours > 24 ? 'm-d H:i' : 'H:i');
    }
}
