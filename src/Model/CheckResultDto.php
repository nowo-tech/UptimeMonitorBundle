<?php

declare(strict_types=1);

namespace Nowo\UptimeMonitorBundle\Model;

use Nowo\UptimeMonitorBundle\Enum\CheckStatus;

/**
 * Result of a single monitor check execution (before persistence).
 */
final class CheckResultDto
{
    /**
     * @param array<string, mixed>|null $metadata
     */
    public function __construct(
        public readonly CheckStatus $status,
        public readonly int $latencyMs,
        public readonly ?int $statusCode = null,
        public readonly ?string $message = null,
        public readonly ?array $metadata = null,
    ) {
    }
}
