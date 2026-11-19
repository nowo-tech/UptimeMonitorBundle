<?php

declare(strict_types=1);

namespace Nowo\UptimeMonitorBundle\Tests\Unit\Search;

use Nowo\UptimeMonitorBundle\Entity\Monitor;
use Nowo\UptimeMonitorBundle\Entity\Tenant;
use Nowo\UptimeMonitorBundle\Enum\CheckStatus;
use Nowo\UptimeMonitorBundle\Enum\MonitorType;
use Nowo\UptimeMonitorBundle\Search\MonitorSearchHit;
use Nowo\UptimeMonitorBundle\Search\MonitorSearchMapper;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;

#[CoversClass(MonitorSearchMapper::class)]
#[CoversClass(MonitorSearchHit::class)]
final class MonitorSearchMapperTest extends TestCase
{
    public function testMapsAnHttpMonitorWithItsOriginAndStatus(): void
    {
        $monitor = $this->monitor(MonitorType::Https, 'https://user:pw@api.example.com:8443/p?token=SECRET');
        $monitor->setLastKnownStatus(CheckStatus::Down);

        $hit = (new MonitorSearchMapper())->map($monitor, true);

        self::assertSame(7, $hit->id);
        self::assertSame('API', $hit->name);
        self::assertSame('https', $hit->type);
        self::assertSame('acme', $hit->tenantSlug);
        self::assertSame('https://api.example.com:8443', $hit->url);
        self::assertSame('down', $hit->status);
        self::assertFalse($hit->paused);
    }

    public function testStatusAndPausedAreHiddenWhenNotAllowed(): void
    {
        $monitor = $this->monitor(MonitorType::Https, 'https://a.example.com');
        $monitor->setLastKnownStatus(CheckStatus::Up)->setPaused(true);

        $hit = (new MonitorSearchMapper())->map($monitor, false);

        self::assertNull($hit->status);
        self::assertFalse($hit->paused);
        self::assertTrue((new MonitorSearchMapper())->map($monitor, true)->paused);
    }

    public function testGroupsAndPushMonitorsShowNoUrl(): void
    {
        self::assertSame('', (new MonitorSearchMapper())->map($this->monitor(MonitorType::Group, ''), true)->url);
        self::assertSame('', (new MonitorSearchMapper())->map($this->monitor(MonitorType::Push, 'pushtarget-SECRET'), true)->url);
        self::assertTrue(MonitorSearchMapper::hasTarget(MonitorType::Tcp));
        self::assertFalse(MonitorSearchMapper::hasTarget(MonitorType::Push));
    }

    private function monitor(MonitorType $type, string $target): Monitor
    {
        $monitor = new Monitor(new Tenant('acme', 'Acme'), 'API', $type, $target);
        (new ReflectionProperty(Monitor::class, 'id'))->setValue($monitor, 7);

        return $monitor;
    }
}
