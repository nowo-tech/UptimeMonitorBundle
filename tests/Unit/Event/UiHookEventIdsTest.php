<?php

declare(strict_types=1);

namespace Nowo\UptimeMonitorBundle\Tests\Unit\Event;

use DateTimeImmutable;
use Nowo\UptimeMonitorBundle\Entity\Monitor;
use Nowo\UptimeMonitorBundle\Entity\Tenant;
use Nowo\UptimeMonitorBundle\Enum\MonitorType;
use Nowo\UptimeMonitorBundle\Event\MonitorShowLiveUpdatesEvent;
use Nowo\UptimeMonitorBundle\Event\StatusPageMonitorsEvent;
use Nowo\UptimeMonitorBundle\Event\UptimePageEvent;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;

/**
 * 1.36 (REQ-ARCH-001 rules 1 and 4): the three UI hook events carry ids and an occurred-at; the entity accessors keep
 * working (deprecated in the docblocks only).
 */
#[CoversClass(StatusPageMonitorsEvent::class)]
#[CoversClass(MonitorShowLiveUpdatesEvent::class)]
#[CoversClass(UptimePageEvent::class)]
final class UiHookEventIdsTest extends TestCase
{
    public function testTheStatusPageNarrowsByIds(): void
    {
        $tenant = new Tenant('main', 'Main');
        $api    = $this->monitor($tenant, 'API', 4);
        $web    = $this->monitor($tenant, 'Web', 9);
        $db     = $this->monitor($tenant, 'DB', 12);
        $at     = new DateTimeImmutable('2026-11-21 10:00:00');
        $event  = new StatusPageMonitorsEvent($tenant, [$api, $web, $db, new Monitor($tenant, 'Unsaved', MonitorType::Https, 'https://u.test')], $at);

        self::assertSame('main', $event->getTenantSlug());
        self::assertSame('main', $event->tenantSlug);
        self::assertSame($at, $event->getOccurredAt());
        self::assertSame([4, 9, 12], $event->getMonitorIds(), 'a monitor without id is left out');

        $event->keepMonitorIds([12, 4, 999]);
        self::assertSame([4, 12], $event->getMonitorIds(), 'page order kept; unknown ids cannot add monitors');
        self::assertSame([$api, $db], $event->getMonitors(), 'the deprecated accessor still works');
        self::assertSame($tenant, $event->tenant);

        $event->keepMonitorIds([]);
        self::assertSame([], $event->getMonitors());
        self::assertGreaterThanOrEqual($at, (new StatusPageMonitorsEvent($tenant, []))->getOccurredAt(), 'defaults to now');
    }

    public function testTheLiveUpdatesEventCarriesTheMonitorId(): void
    {
        $monitor = $this->monitor(new Tenant('main', 'Main'), 'API', 7);
        $at      = new DateTimeImmutable('2026-11-21 10:00:00');
        $event   = new MonitorShowLiveUpdatesEvent('main', $monitor, '/main/api/monitors/7/history', $at);

        self::assertSame(7, $event->getMonitorId());
        self::assertSame(7, $event->monitorId);
        self::assertSame('main', $event->getTenantSlug());
        self::assertSame($at, $event->getOccurredAt());
        self::assertSame($monitor, $event->monitor, 'the deprecated accessor still works');
        self::assertGreaterThan($at, (new MonitorShowLiveUpdatesEvent('main', $monitor, '/h'))->occurredAt, 'defaults to now');
    }

    public function testThePageEventCarriesTheMonitorIdOrNull(): void
    {
        $monitor = $this->monitor(new Tenant('main', 'Main'), 'API', 5);
        $at      = new DateTimeImmutable('2026-11-21 10:00:00');
        $show    = new UptimePageEvent('monitor_show', 'main', $monitor, $at);
        $board   = new UptimePageEvent('dashboard', 'main');

        self::assertSame(['monitor_show', 'main', 5, $at], [$show->getPage(), $show->getTenantSlug(), $show->getMonitorId(), $show->getOccurredAt()]);
        self::assertSame($monitor, $show->monitor);
        self::assertNull($board->getMonitorId());
        self::assertNull($board->monitorId);
        self::assertSame('dashboard', $board->getPage());
        self::assertGreaterThan($at, $board->occurredAt, 'defaults to now');
    }

    private function monitor(Tenant $tenant, string $name, int $id): Monitor
    {
        $monitor = new Monitor($tenant, $name, MonitorType::Https, 'https://' . $name . '.test');
        (new ReflectionProperty(Monitor::class, 'id'))->setValue($monitor, $id);

        return $monitor;
    }
}
