<?php

declare(strict_types=1);

namespace Nowo\UptimeMonitorBundle\Tests\Unit\Event;

use InvalidArgumentException;
use Nowo\UptimeMonitorBundle\Entity\Monitor;
use Nowo\UptimeMonitorBundle\Entity\Tenant;
use Nowo\UptimeMonitorBundle\Enum\MonitorType;
use Nowo\UptimeMonitorBundle\Event\MonitorShowLiveUpdatesEvent;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(MonitorShowLiveUpdatesEvent::class)]
final class MonitorShowLiveUpdatesEventTest extends TestCase
{
    private function event(): MonitorShowLiveUpdatesEvent
    {
        return new MonitorShowLiveUpdatesEvent('main', new Monitor(new Tenant('main', 'Main'), 'API', MonitorType::Https, 'https://x.test'), '/main/api/monitors/4/history');
    }

    public function testTheHistoryUrlIsKeptUntilAListenerTurnsThePollingOff(): void
    {
        $event = $this->event();
        self::assertSame('main', $event->tenantSlug);
        self::assertSame('API', $event->monitor->getName());
        self::assertSame('/main/api/monitors/4/history', $event->getApiHistoryUrl());
        self::assertSame([], $event->getRootAttributes());

        $event->setApiHistoryUrl(null);
        self::assertNull($event->getApiHistoryUrl());
        self::assertSame('/main/api/monitors/4/history', $event->historyUrl, 'the bundle URL stays readable for a peer');

        $event->setApiHistoryUrl('/proxy/history');
        self::assertSame('/proxy/history', $event->getApiHistoryUrl());
        $event->setApiHistoryUrl('  ');
        self::assertNull($event->getApiHistoryUrl());
    }

    public function testRootAttributesAreAddedReplacedAndRemoved(): void
    {
        $event = $this->event();
        $event->setRootAttribute('data-controller', 'live-updates-watch');
        $event->setRootAttribute('data-live-updates-watch-interval-value', 15000);
        $event->setRootAttribute('data-ready', true);
        $event->setRootAttribute('aria-live', 'polite');
        $event->setRootAttribute('class', 'host-root');
        $event->setRootAttribute('data-gone', 'x');
        $event->setRootAttribute('data-gone', false);
        $event->setRootAttribute('role', 'region');
        $event->setRootAttribute('role', null);

        self::assertSame([
            'data-controller'                        => 'live-updates-watch',
            'data-live-updates-watch-interval-value' => '15000',
            'data-ready'                             => true,
            'aria-live'                              => 'polite',
            'class'                                  => 'host-root',
        ], $event->getRootAttributes());
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function unsafeNames(): iterable
    {
        foreach (['onclick', 'onload', 'style', 'id', 'data-api-history', 'data-monitor-id', 'data-series', 'data-latency-series', 'href', 'src', 'Data-x', 'data-', 'data-a b', 'data-x"', 'aria-', 'formaction', 'data-a:b', ''] as $name) {
            yield $name === '' ? '(empty)' : $name => [$name];
        }
    }

    #[DataProvider('unsafeNames')]
    public function testUnsafeOrReservedNamesAreRefused(string $name): void
    {
        self::assertFalse(MonitorShowLiveUpdatesEvent::isAllowedAttribute($name));
        $this->expectException(InvalidArgumentException::class);
        $this->event()->setRootAttribute($name, 'x');
    }

    public function testAllowedNames(): void
    {
        foreach (['data-controller', 'data-a_b.c-d', 'aria-label', 'role', 'title', 'lang', 'dir', 'class'] as $name) {
            self::assertTrue(MonitorShowLiveUpdatesEvent::isAllowedAttribute($name), $name);
        }
    }
}
