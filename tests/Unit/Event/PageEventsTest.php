<?php

declare(strict_types=1);

namespace Nowo\UptimeMonitorBundle\Tests\Unit\Event;

use Nowo\UiKitBundle\View\Action;
use Nowo\UptimeMonitorBundle\Entity\Monitor;
use Nowo\UptimeMonitorBundle\Entity\Tenant;
use Nowo\UptimeMonitorBundle\Enum\MonitorType;
use Nowo\UptimeMonitorBundle\Event\StatusPageMonitorsEvent;
use Nowo\UptimeMonitorBundle\Event\UptimePageEvent;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(UptimePageEvent::class)]
#[CoversClass(StatusPageMonitorsEvent::class)]
final class PageEventsTest extends TestCase
{
    public function testPageEventCollectsActionsAndSections(): void
    {
        $monitor = new Monitor(new Tenant('main', 'Main'), 'API', MonitorType::Https, 'https://x.test');
        $event   = new UptimePageEvent('monitor_show', 'main', $monitor);
        $share   = new Action('share', 'Share', '/share');
        $event->addAction($share);
        $event->addSection('host/_share.html.twig', ['monitor' => $monitor]);
        $event->addSection('host/_intro.html.twig', [], 'before');
        $event->addSection('host/_other.html.twig', [], 'anywhere');

        self::assertSame('monitor_show', $event->page);
        self::assertSame('main', $event->tenantSlug);
        self::assertSame($monitor, $event->monitor);
        self::assertSame([$share], $event->getActions());
        self::assertSame([['template' => 'host/_intro.html.twig', 'context' => []]], $event->getSections('before'));
        self::assertSame([
            ['template' => 'host/_share.html.twig', 'context' => ['monitor' => $monitor]],
            ['template' => 'host/_other.html.twig', 'context' => []],
        ], $event->getSections('after'));
    }

    public function testStatusPageMonitorsCanBeReplaced(): void
    {
        $tenant = new Tenant('main', 'Main');
        $api    = new Monitor($tenant, 'API', MonitorType::Https, 'https://x.test');
        $web    = new Monitor($tenant, 'Web', MonitorType::Https, 'https://y.test');
        $event  = new StatusPageMonitorsEvent($tenant, [$api, $web]);

        self::assertSame($tenant, $event->tenant);
        self::assertSame([$api, $web], $event->getMonitors());

        $event->setMonitors([$web]);
        self::assertSame([$web], $event->getMonitors());
    }
}
