<?php

declare(strict_types=1);

namespace Nowo\UptimeMonitorBundle\Tests\Unit\Limits;

use Nowo\UptimeMonitorBundle\Limits\ConfigurableTenantLimits;
use Nowo\UptimeMonitorBundle\Limits\TenantQuota;
use Nowo\UptimeMonitorBundle\Repository\MonitorRepository;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(TenantQuota::class)]
#[CoversClass(ConfigurableTenantLimits::class)]
final class TenantQuotaTest extends TestCase
{
    private function quota(int $max, int $count): TenantQuota
    {
        $monitors = $this->createStub(MonitorRepository::class);
        $monitors->method('countChecksByTenantSlug')->willReturn($count);

        return new TenantQuota(new ConfigurableTenantLimits($max, 45), $monitors);
    }

    public function testNoLimit(): void
    {
        $quota = $this->quota(0, 500);

        self::assertNull($quota->remaining('main'));
        self::assertTrue($quota->canAdd('main'));
        self::assertSame(0, $quota->max('main'));
    }

    public function testLimitCountsTheTenantMonitors(): void
    {
        self::assertSame(2, $this->quota(5, 3)->remaining('main'));
        self::assertTrue($this->quota(5, 3)->canAdd('main'));
        self::assertSame(0, $this->quota(5, 7)->remaining('main'));
        self::assertFalse($this->quota(5, 5)->canAdd('main'));
    }

    public function testMinimumInterval(): void
    {
        self::assertSame(45, $this->quota(0, 0)->minIntervalSeconds('main'));
        self::assertSame(30, (new ConfigurableTenantLimits())->minIntervalSeconds('any'));
        self::assertSame(0, (new ConfigurableTenantLimits())->maxMonitors('any'));
    }
}
