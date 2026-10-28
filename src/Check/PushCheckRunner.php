<?php

declare(strict_types=1);

namespace Nowo\UptimeMonitorBundle\Check;

use Nowo\UptimeMonitorBundle\Entity\Monitor;
use Nowo\UptimeMonitorBundle\Enum\CheckStatus;
use Nowo\UptimeMonitorBundle\Enum\MonitorType;
use Nowo\UptimeMonitorBundle\Model\CheckResultDto;
use Nowo\UptimeMonitorBundle\Push\PushThresholds;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\Clock\NativeClock;

use function is_int;
use function max;
use function sprintf;

/**
 * Push monitors (since 1.14) make no network call: on each interval the last pushed sample is judged against the
 * thresholds, and no push within interval + grace is `down` (server, agent or network gone). Consecutive breaches
 * before `down` are the monitor's retries.
 */
final readonly class PushCheckRunner implements CheckRunnerInterface
{
    /**
     * @param ClockInterface $clock Reference clock of the staleness check
     */
    public function __construct(
        private ClockInterface $clock = new NativeClock(),
    ) {
    }

    /**
     * Whether the monitor is a push monitor.
     */
    public function supports(Monitor $monitor): bool
    {
        return $monitor->getType() === MonitorType::Push;
    }

    /**
     * Judges the last push, or reports the silence.
     */
    public function run(Monitor $monitor): CheckResultDto
    {
        $now     = $this->clock->now()->getTimestamp();
        $last    = $monitor->getPushLastAt();
        $allowed = $monitor->getIntervalSeconds() + self::graceSeconds($monitor);
        $since   = $now - ($last ?? $monitor->getCreatedAt())->getTimestamp();

        if ($since > $allowed) {
            return new CheckResultDto(CheckStatus::Down, 0, null, $last === null
                ? 'No push received yet'
                : sprintf('No push for %d s (allowed %d s)', $since, $allowed));
        }
        $sample = $monitor->getPushSample();
        if ($last === null || $sample === null) {
            return new CheckResultDto(CheckStatus::Unknown, 0, null, 'Waiting for the first push');
        }

        return PushThresholds::of($monitor)->evaluate($sample);
    }

    /**
     * Seconds a push may be late beyond the interval (config `push_grace_seconds`; default one interval).
     */
    public static function graceSeconds(Monitor $monitor): int
    {
        $grace = $monitor->getConfig()['push_grace_seconds'] ?? null;

        return is_int($grace) ? max(0, $grace) : $monitor->getIntervalSeconds();
    }
}
