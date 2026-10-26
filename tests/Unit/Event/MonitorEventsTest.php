<?php

declare(strict_types=1);

namespace Nowo\UptimeMonitorBundle\Tests\Unit\Event;

use Nowo\UptimeMonitorBundle\Entity\Incident;
use Nowo\UptimeMonitorBundle\Entity\Monitor;
use Nowo\UptimeMonitorBundle\Entity\Tenant;
use Nowo\UptimeMonitorBundle\Enum\CheckStatus;
use Nowo\UptimeMonitorBundle\Enum\MonitorType;
use Nowo\UptimeMonitorBundle\Event\MonitorRecoveredEvent;
use Nowo\UptimeMonitorBundle\Event\MonitorWentDownEvent;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(MonitorWentDownEvent::class)]
#[CoversClass(MonitorRecoveredEvent::class)]
final class MonitorEventsTest extends TestCase
{
    public function testEventsExposeMonitorIncidentAndMessage(): void
    {
        $monitor  = new Monitor(new Tenant('main', 'Main'), 'API', MonitorType::Https, 'https://x.test');
        $incident = new Incident($monitor, CheckStatus::Down, 'timeout');

        $down = new MonitorWentDownEvent($monitor, $incident, 'timeout');
        self::assertSame($monitor, $down->monitor);
        self::assertSame($incident, $down->incident);
        self::assertSame('timeout', $down->message);

        $recovered = new MonitorRecoveredEvent($monitor, $incident);
        self::assertSame($monitor, $recovered->monitor);
        self::assertSame($incident, $recovered->incident);
    }
}
