<?php

declare(strict_types=1);

namespace Nowo\UptimeMonitorBundle\Query;

use DateTimeImmutable;
use Nowo\UptimeMonitorBundle\Enum\CheckStatus;

/**
 * The latest stored check result of a monitor (since 1.35), as plain values.
 */
final readonly class LatestCheckView
{
    /**
     * @param DateTimeImmutable $checkedAt When the check ran
     * @param CheckStatus $status Its result
     * @param int $latencyMs Latency in milliseconds
     * @param int|null $statusCode HTTP status code, when the check has one
     */
    public function __construct(
        public DateTimeImmutable $checkedAt,
        public CheckStatus $status,
        public int $latencyMs,
        public ?int $statusCode = null,
    ) {
    }
}
