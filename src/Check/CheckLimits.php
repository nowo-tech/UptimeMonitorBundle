<?php

declare(strict_types=1);

namespace Nowo\UptimeMonitorBundle\Check;

use function max;
use function min;

/**
 * Global upper bounds applied to every monitor check, whatever its own configuration says (SEC-004 M1).
 *
 * A monitor manager controls the per-monitor timeout, redirects and target; these caps keep one monitor from
 * stalling (tarpit) or exhausting memory (huge body) in the scan shared by every tenant. Values come from
 * `nowo_uptime_monitor.checks.*` (`max_timeout_seconds`, `max_body_bytes`, `max_redirects`).
 */
final readonly class CheckLimits
{
    public const DEFAULT_MAX_TIMEOUT_SECONDS = 60.0;
    public const DEFAULT_MAX_BODY_BYTES      = 1_048_576;
    public const DEFAULT_MAX_REDIRECTS       = 20;

    /**
     * @param float $maxTimeoutSeconds upper bound of any per-monitor timeout (seconds, min 1)
     * @param int $maxBodyBytes bytes of an HTTP response body read for keyword matching (min 1)
     * @param int $maxRedirects upper bound of the per-monitor redirect limit (min 0)
     */
    public function __construct(
        public float $maxTimeoutSeconds = self::DEFAULT_MAX_TIMEOUT_SECONDS,
        public int $maxBodyBytes = self::DEFAULT_MAX_BODY_BYTES,
        public int $maxRedirects = self::DEFAULT_MAX_REDIRECTS,
    ) {
    }

    /**
     * Clamps a per-monitor timeout to [1, max_timeout_seconds].
     */
    public function clampTimeout(float $seconds): float
    {
        return min(max(1.0, $seconds), max(1.0, $this->maxTimeoutSeconds));
    }

    /**
     * Clamps a per-monitor redirect limit to [0, max_redirects].
     */
    public function clampRedirects(int $redirects): int
    {
        return min(max(0, $redirects), max(0, $this->maxRedirects));
    }
}
