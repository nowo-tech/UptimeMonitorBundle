<?php

declare(strict_types=1);

namespace Nowo\UptimeMonitorBundle\Tests\Unit\Service;

use Nowo\UptimeMonitorBundle\Entity\Monitor;
use Nowo\UptimeMonitorBundle\Entity\Tenant;
use Nowo\UptimeMonitorBundle\Enum\CheckStatus;
use Nowo\UptimeMonitorBundle\Enum\MonitorType;
use Nowo\UptimeMonitorBundle\Model\CheckResultDto;
use Nowo\UptimeMonitorBundle\Service\CheckLatencyNormalizer;
use PHPUnit\Framework\TestCase;

/**
 * @covers \Nowo\UptimeMonitorBundle\Service\CheckLatencyNormalizer
 */
final class CheckLatencyNormalizerTest extends TestCase
{
    public function testNoFloorWhenGlobalZero(): void
    {
        $monitor    = $this->monitor();
        $normalizer = new CheckLatencyNormalizer(['min_latency_ms' => 0]);

        self::assertSame(0, $normalizer->normalize($monitor, 0));
        self::assertSame(3, $normalizer->normalize($monitor, 3));
    }

    public function testGlobalFloor(): void
    {
        $monitor    = $this->monitor();
        $normalizer = new CheckLatencyNormalizer(['min_latency_ms' => 5]);

        self::assertSame(5, $normalizer->normalize($monitor, 0));
        self::assertSame(5, $normalizer->normalize($monitor, 4));
        self::assertSame(12, $normalizer->normalize($monitor, 12));
    }

    public function testMonitorConfigOverridesGlobal(): void
    {
        $monitor = $this->monitor();
        $monitor->setConfig(['min_latency_ms' => 10, 'url' => 'https://example.test']);
        $normalizer = new CheckLatencyNormalizer(['min_latency_ms' => 1]);

        self::assertSame(10, $normalizer->normalize($monitor, 2));
    }

    public function testNormalizeDtoReturnsNewInstanceWhenAdjusted(): void
    {
        $monitor    = $this->monitor();
        $normalizer = new CheckLatencyNormalizer(['min_latency_ms' => 1]);
        $dto        = new CheckResultDto(CheckStatus::Up, 0, 200);

        $adjusted = $normalizer->normalizeDto($monitor, $dto);

        self::assertNotSame($dto, $adjusted);
        self::assertSame(1, $adjusted->latencyMs);
    }

    public function testNormalizeDtoReusesInstanceWhenUnchanged(): void
    {
        $monitor    = $this->monitor();
        $normalizer = new CheckLatencyNormalizer(['min_latency_ms' => 0]);
        $dto        = new CheckResultDto(CheckStatus::Up, 42, 200);

        self::assertSame($dto, $normalizer->normalizeDto($monitor, $dto));
    }

    private function monitor(): Monitor
    {
        return new Monitor(new Tenant('main', 'Main'), 'API', MonitorType::Https, 'https://example.test');
    }
}
