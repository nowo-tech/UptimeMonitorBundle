<?php

declare(strict_types=1);

namespace Nowo\UptimeMonitorBundle\Tests\Unit\Event;

use Nowo\UptimeMonitorBundle\Entity\Monitor;
use Nowo\UptimeMonitorBundle\Entity\Tenant;
use Nowo\UptimeMonitorBundle\Enum\MonitorType;
use Nowo\UptimeMonitorBundle\Event\MonitorQuotaCheckEvent;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(MonitorQuotaCheckEvent::class)]
final class MonitorQuotaCheckEventTest extends TestCase
{
    public function testAllowedUntilDeniedAndTheFirstReasonWins(): void
    {
        $monitor = new Monitor(new Tenant('main', 'Main'), 'API', MonitorType::Https, 'https://x.test');
        $check   = new MonitorQuotaCheckEvent(MonitorQuotaCheckEvent::ACTION_RESUME, 'main', MonitorType::Https, $monitor);

        self::assertSame(['resume', 'main', MonitorType::Https, $monitor], [$check->action, $check->tenantSlug, $check->type, $check->monitor]);
        self::assertFalse($check->isDenied());
        self::assertNull($check->getReason());

        $check->deny('Plan limit reached.');
        $check->deny('Another reason.');

        self::assertTrue($check->isDenied());
        self::assertSame('Plan limit reached.', $check->getReason());
    }

    public function testForChangeOfARunningMonitor(): void
    {
        $monitor = new Monitor(new Tenant('main', 'Main'), 'API', MonitorType::Https, 'https://x.test');

        self::assertCount(0, MonitorQuotaCheckEvent::forChange('main', $monitor, MonitorType::Https, false));
        self::assertCount(0, MonitorQuotaCheckEvent::forChange('main', $monitor, MonitorType::Https, true), 'pausing is never checked');

        [$create] = MonitorQuotaCheckEvent::forChange('main', $monitor, MonitorType::Push, false);
        self::assertSame(['create', 'main', MonitorType::Push, $monitor, MonitorType::Https], [$create->action, $create->tenantSlug, $create->type, $create->monitor, $create->previousType]);

    }

    public function testForChangeOfAPausedMonitor(): void
    {
        $monitor = (new Monitor(new Tenant('main', 'Main'), 'API', MonitorType::Https, 'https://x.test'))->setPaused(true);
        self::assertCount(0, MonitorQuotaCheckEvent::forChange('main', $monitor, MonitorType::Https, true), 'staying paused');

        [$resume] = MonitorQuotaCheckEvent::forChange('main', $monitor, MonitorType::Https, false);
        self::assertSame(['resume', 'main', MonitorType::Https, $monitor, null], [$resume->action, $resume->tenantSlug, $resume->type, $resume->monitor, $resume->previousType]);

        $both = MonitorQuotaCheckEvent::forChange('main', $monitor, MonitorType::Push, false);
        self::assertSame(['create', 'resume'], [$both[0]->action, $both[1]->action]);
        self::assertSame(MonitorType::Push, $both[1]->type);
    }
}
