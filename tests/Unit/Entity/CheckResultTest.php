<?php

declare(strict_types=1);

namespace Nowo\UptimeMonitorBundle\Tests\Unit\Entity;

use DateTimeImmutable;
use Nowo\UptimeMonitorBundle\Entity\CheckResult;
use Nowo\UptimeMonitorBundle\Entity\Monitor;
use Nowo\UptimeMonitorBundle\Entity\Tenant;
use Nowo\UptimeMonitorBundle\Enum\CheckStatus;
use Nowo\UptimeMonitorBundle\Enum\MonitorType;
use PHPUnit\Framework\TestCase;

/**
 * @covers \Nowo\UptimeMonitorBundle\Entity\CheckResult
 */
final class CheckResultTest extends TestCase
{
    public function testGetters(): void
    {
        $monitor = new Monitor(new Tenant('main', 'Main'), 'API', MonitorType::Https, 'https://x.test');
        $result  = new CheckResult($monitor, CheckStatus::Up, 42, 200, 'ok', ['foo' => 'bar']);

        self::assertSame($monitor, $result->getMonitor());
        self::assertSame(CheckStatus::Up, $result->getStatus());
        self::assertSame(42, $result->getLatencyMs());
        self::assertSame(200, $result->getStatusCode());
        self::assertSame('ok', $result->getMessage());
        self::assertInstanceOf(DateTimeImmutable::class, $result->getCheckedAt());
    }
}
