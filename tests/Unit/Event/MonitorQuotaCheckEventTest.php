<?php

declare(strict_types=1);

namespace Nowo\UptimeMonitorBundle\Tests\Unit\Event;

use LogicException;
use Nowo\UptimeMonitorBundle\Entity\Monitor;
use Nowo\UptimeMonitorBundle\Entity\Tenant;
use Nowo\UptimeMonitorBundle\Enum\MonitorType;
use Nowo\UptimeMonitorBundle\Event\MonitorQuotaCheckEvent;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\IgnoreDeprecations;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;

#[CoversClass(MonitorQuotaCheckEvent::class)]
final class MonitorQuotaCheckEventTest extends TestCase
{
    private const DEPRECATION = 'Since nowo-tech/uptime-monitor-bundle 1.35: Reading Nowo\UptimeMonitorBundle\Event\MonitorQuotaCheckEvent::$monitor is deprecated and the property is removed in 2.0: use $monitorId and $paused.';

    public function testAllowedUntilDeniedAndTheFirstReasonWins(): void
    {
        $monitor = $this->monitor(7);
        $check   = new MonitorQuotaCheckEvent(MonitorQuotaCheckEvent::ACTION_RESUME, 'main', MonitorType::Https, $monitor);

        self::assertSame(['resume', 'main', MonitorType::Https, 7, false], [$check->action, $check->tenantSlug, $check->type, $check->monitorId, $check->paused]);
        self::assertFalse($check->isDenied());
        self::assertNull($check->getReason());

        $check->deny('Plan limit reached.');
        $check->deny('Another reason.');

        self::assertTrue($check->isDenied());
        self::assertSame('Plan limit reached.', $check->getReason());
    }

    public function testANewMonitorCarriesItsPausedStateAndNoId(): void
    {
        $check = new MonitorQuotaCheckEvent(MonitorQuotaCheckEvent::ACTION_CREATE, 'main', MonitorType::Push, paused: true);

        self::assertNull($check->monitorId);
        self::assertTrue($check->paused);
        self::assertFalse(isset($check->monitor));
        self::assertFalse((new MonitorQuotaCheckEvent(MonitorQuotaCheckEvent::ACTION_CREATE, 'main', MonitorType::Push))->paused);
    }

    public function testExplicitScalarsWinOverTheMonitor(): void
    {
        $check = new MonitorQuotaCheckEvent(MonitorQuotaCheckEvent::ACTION_CREATE, 'main', MonitorType::Push, $this->monitor(3, true), MonitorType::Https, false, 9);

        self::assertSame([9, false], [$check->monitorId, $check->paused]);
    }

    #[IgnoreDeprecations]
    public function testReadingTheEntityAccessorIsDeprecatedButStillWorks(): void
    {
        $monitor = $this->monitor(5);
        $check   = new MonitorQuotaCheckEvent(MonitorQuotaCheckEvent::ACTION_RESUME, 'main', MonitorType::Https, $monitor);

        self::assertTrue(isset($check->monitor), 'isset() triggers nothing');
        $this->expectUserDeprecationMessage(self::DEPRECATION);
        self::assertSame($monitor, $check->monitor);
        self::assertFalse($check->monitor->isPaused());
    }

    public function testAnyOtherUndefinedPropertyFails(): void
    {
        $check = new MonitorQuotaCheckEvent(MonitorQuotaCheckEvent::ACTION_RESUME, 'main', MonitorType::Https);

        self::assertFalse(isset($check->other));
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('Undefined property ' . MonitorQuotaCheckEvent::class . '::$other.');
        $check->__get('other');
    }

    public function testTheEntityAccessorCannotBeAssigned(): void
    {
        $check = new MonitorQuotaCheckEvent(MonitorQuotaCheckEvent::ACTION_RESUME, 'main', MonitorType::Https);

        try {
            $check->__set('monitor', self::monitor(1));
            self::fail('read-only');
        } catch (LogicException $e) {
            self::assertStringContainsString('$monitor is read-only', $e->getMessage());
        }

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('Cannot set undefined property ' . MonitorQuotaCheckEvent::class . '::$other.');
        $check->__set('other', 1);
    }

    public function testForChangeOfARunningMonitor(): void
    {
        $monitor = $this->monitor(11);

        self::assertCount(0, MonitorQuotaCheckEvent::forChange('main', $monitor, MonitorType::Https, false));
        self::assertCount(0, MonitorQuotaCheckEvent::forChange('main', $monitor, MonitorType::Https, true), 'pausing is never checked');

        [$create] = MonitorQuotaCheckEvent::forChange('main', $monitor, MonitorType::Push, false);
        self::assertSame(['create', 'main', MonitorType::Push, 11, false, MonitorType::Https], [$create->action, $create->tenantSlug, $create->type, $create->monitorId, $create->paused, $create->previousType]);
    }

    public function testForChangeOfAPausedMonitor(): void
    {
        $monitor = $this->monitor(12, true);
        self::assertCount(0, MonitorQuotaCheckEvent::forChange('main', $monitor, MonitorType::Https, true), 'staying paused');

        [$resume] = MonitorQuotaCheckEvent::forChange('main', $monitor, MonitorType::Https, false);
        self::assertSame(['resume', 'main', MonitorType::Https, 12, true, null], [$resume->action, $resume->tenantSlug, $resume->type, $resume->monitorId, $resume->paused, $resume->previousType]);

        $both = MonitorQuotaCheckEvent::forChange('main', $monitor, MonitorType::Push, false);
        self::assertSame(['create', 'resume'], [$both[0]->action, $both[1]->action]);
        self::assertTrue($both[0]->paused, 'a type change of a paused monitor says so (a host counts it on resume)');
        self::assertSame(MonitorType::Push, $both[1]->type);
    }

    private function monitor(int $id, bool $paused = false): Monitor
    {
        $monitor = (new Monitor(new Tenant('main', 'Main'), 'API', MonitorType::Https, 'https://x.test'))->setPaused($paused);
        (new ReflectionProperty(Monitor::class, 'id'))->setValue($monitor, $id);

        return $monitor;
    }
}
