<?php

declare(strict_types=1);

namespace Nowo\UptimeMonitorBundle\Push;

use Nowo\UptimeMonitorBundle\Entity\Monitor;
use Nowo\UptimeMonitorBundle\Enum\CheckStatus;
use Nowo\UptimeMonitorBundle\Model\CheckResultDto;

use function is_array;
use function is_float;
use function is_int;
use function is_string;
use function max;
use function sprintf;

/**
 * Warning and critical limits of a push monitor (monitor config `push_thresholds`, since 1.14) and the status a sample
 * gives: a warning makes it `degraded`, a critical `down`; the worst metric wins and names the message. The agent's
 * own opinion is never asked: only its numbers are.
 */
final readonly class PushThresholds
{
    /** @var array<string, array{0: float|null, 1: float|null}> metric => [warn, critical] */
    public const DEFAULTS = [
        'disk'   => [85.0, 95.0],
        'inodes' => [85.0, 95.0],
        'memory' => [90.0, 97.0],
        'cpu'    => [85.0, 95.0],
        'swap'   => [50.0, 80.0],
        'load'   => [1.5, 3.0],
    ];

    /**
     * @param array<string, array{0: float|null, 1: float|null}> $limits
     */
    public function __construct(
        public array $limits = self::DEFAULTS,
    ) {
    }

    /**
     * The monitor's limits; a metric missing from its config keeps the default, a null limit is off.
     */
    public static function of(Monitor $monitor): self
    {
        return self::fromConfig($monitor->getConfig());
    }

    /**
     * The limits of a monitor config (`push_thresholds`).
     *
     * @param array<string, mixed> $monitorConfig
     */
    public static function fromConfig(array $monitorConfig): self
    {
        $config = $monitorConfig['push_thresholds'] ?? [];
        $limits = self::DEFAULTS;
        foreach (self::DEFAULTS as $metric => $default) {
            $pair = is_array($config) ? ($config[$metric] ?? null) : null;
            if (is_array($pair)) {
                $limits[$metric] = [self::limit($pair[0] ?? null), self::limit($pair[1] ?? null)];
            }
        }

        return new self($limits);
    }

    /**
     * The config value of these limits (`push_thresholds`).
     *
     * @return array<string, array{0: float|null, 1: float|null}>
     */
    public function toConfig(): array
    {
        return $this->limits;
    }

    /**
     * The status of a sample: `up`, `degraded` (a warning) or `down` (a critical), the message naming the worst metric,
     * and the sample as metadata.
     *
     * @param array<string, mixed> $sample a sample of {@see PushPayload::parse()}
     */
    public function evaluate(array $sample): CheckResultDto
    {
        $worst   = CheckStatus::Up;
        $message = null;
        foreach ($this->readings($sample) as [$metric, $label, $value]) {
            [$warn, $critical] = $this->limits[$metric];
            $status            = match (true) {
                $critical !== null && $value >= $critical => CheckStatus::Down,
                $warn !== null && $value >= $warn         => CheckStatus::Degraded,
                default                                   => CheckStatus::Up,
            };
            if ($this->rank($status) > $this->rank($worst)) {
                $worst   = $status;
                $message = sprintf(
                    '%s at %s (%s ≥ %s)',
                    $label,
                    $metric === 'load' ? sprintf('%.2f per core', $value) : sprintf('%.1f %%', $value),
                    $status === CheckStatus::Down ? 'critical' : 'warning',
                    $metric === 'load' ? sprintf('%.2f', $status === CheckStatus::Down ? $critical : $warn) : sprintf('%.0f %%', $status === CheckStatus::Down ? $critical : $warn),
                );
            }
        }

        return new CheckResultDto($worst, 0, null, $message ?? 'Push received', $sample);
    }

    /**
     * @param array<string, mixed> $sample
     *
     * @return list<array{0: string, 1: string, 2: float}> metric, label, value
     */
    private function readings(array $sample): array
    {
        $readings = [];
        foreach (['cpu' => ['cpu_pct', 'CPU'], 'memory' => ['mem_pct', 'Memory'], 'swap' => ['swap_pct', 'Swap']] as $metric => [$key, $label]) {
            if (is_int($sample[$key] ?? null) || is_float($sample[$key] ?? null)) {
                $readings[] = [$metric, $label, (float) $sample[$key]];
            }
        }
        $load = $sample['load5'] ?? $sample['load1'] ?? null;
        if (is_int($load) || is_float($load)) {
            $cores      = $sample['cpu_count'] ?? 1;
            $readings[] = ['load', 'Load', (float) $load / max(1, is_int($cores) ? $cores : 1)];
        }
        foreach (is_array($sample['disks'] ?? null) ? $sample['disks'] : [] as $disk) {
            if (!is_array($disk) || !is_string($disk['mount'] ?? null)) {
                continue;
            }
            foreach (['disk' => ['used_pct', 'Disk'], 'inodes' => ['inodes_pct', 'Inodes']] as $metric => [$key, $label]) {
                if (is_int($disk[$key] ?? null) || is_float($disk[$key] ?? null)) {
                    $readings[] = [$metric, $label . ' ' . $disk['mount'], (float) $disk[$key]];
                }
            }
        }

        return $readings;
    }

    private static function limit(mixed $value): ?float
    {
        return is_int($value) || is_float($value) ? (float) $value : null;
    }

    private function rank(CheckStatus $status): int
    {
        return match ($status) {
            CheckStatus::Down     => 2,
            CheckStatus::Degraded => 1,
            default               => 0,
        };
    }
}
