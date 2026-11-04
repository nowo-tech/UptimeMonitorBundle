<?php

declare(strict_types=1);

namespace Nowo\UptimeMonitorBundle\Apply;

/**
 * One push monitor entry of a monitors file (since 1.18), validated and with its defaults applied.
 */
final readonly class PushMonitorSpec
{
    /**
     * @param string $tenant Tenant slug (a project UUID in Beacon)
     * @param int $index Position in the tenant's `monitors` array (error paths)
     * @param string $key Stable entry key (`origin_key`)
     * @param string $urlKey Ingest URL key (`push_key`)
     * @param int|null $graceSeconds `push_grace_seconds` (null = one interval)
     * @param bool|null $paused null = unmanaged: the stored value is kept
     * @param array<string, array{0: float|null, 1: float|null}> $thresholds Every metric of `PushThresholds::DEFAULTS`
     */
    public function __construct(
        public string $tenant,
        public int $index,
        public string $key,
        public string $name,
        public string $project,
        public string $description,
        public string $urlKey,
        public int $intervalSeconds,
        public ?int $graceSeconds,
        public int $retries,
        public int $resendNotificationAfterDown,
        public ?bool $paused,
        public array $thresholds,
    ) {
    }

    /**
     * JSON pointer of this entry (or of one of its fields) in the monitors file.
     */
    public function path(string $field = ''): string
    {
        return '/tenants/' . $this->tenant . '/monitors/' . $this->index . ($field !== '' ? '/' . $field : '');
    }
}
