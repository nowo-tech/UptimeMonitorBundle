<?php

declare(strict_types=1);

namespace Nowo\UptimeMonitorBundle\Service;

use Nowo\UptimeMonitorBundle\Entity\Monitor;
use Nowo\UptimeMonitorBundle\Model\CheckResultDto;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Applies configured minimum latency so reported values are not below measurement resolution.
 */
final readonly class CheckLatencyNormalizer
{
    /**
     * @param array<string, mixed> $checksConfig
     */
    public function __construct(
        #[Autowire('%nowo_uptime_monitor.checks%')]
        private array $checksConfig,
    ) {
    }

    /**
     * Raises the measured latency to the configured minimum (`checks.min_latency_ms`), never below 0.
     */
    public function normalize(Monitor $monitor, int $latencyMs): int
    {
        $min = $this->resolveMinLatencyMs($monitor);
        if ($min <= 0) {
            return max(0, $latencyMs);
        }

        return max($min, $latencyMs);
    }

    /**
     * Returns the check result with its latency normalised (the same instance when nothing changes).
     */
    public function normalizeDto(Monitor $monitor, CheckResultDto $dto): CheckResultDto
    {
        $latencyMs = $this->normalize($monitor, $dto->latencyMs);
        if ($latencyMs === $dto->latencyMs) {
            return $dto;
        }

        return new CheckResultDto(
            $dto->status,
            $latencyMs,
            $dto->statusCode,
            $dto->message,
            $dto->metadata,
        );
    }

    private function resolveMinLatencyMs(Monitor $monitor): int
    {
        $config = $monitor->getConfig();
        if (isset($config['min_latency_ms']) && is_numeric($config['min_latency_ms'])) {
            return max(0, (int) $config['min_latency_ms']);
        }

        $global = $this->checksConfig['min_latency_ms'] ?? 0;

        return max(0, (int) $global);
    }
}
