<?php

declare(strict_types=1);

namespace Nowo\UptimeMonitorBundle\Model;

use Nowo\UptimeMonitorBundle\Enum\CheckStatus;

/**
 * Result of a single monitor check execution (before persistence).
 */
final readonly class CheckResultDto
{
    /**
     * @param array<string, mixed>|null $metadata
     */
    public function __construct(
        public CheckStatus $status,
        public int $latencyMs,
        public ?int $statusCode = null,
        public ?string $message = null,
        public ?array $metadata = null,
    ) {
    }
}
