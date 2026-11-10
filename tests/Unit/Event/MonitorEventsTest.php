<?php

declare(strict_types=1);

namespace Nowo\UptimeMonitorBundle\Tests\Unit\Event;

use DateTimeImmutable;
use Nowo\UptimeMonitorBundle\Entity\Incident;
use Nowo\UptimeMonitorBundle\Entity\Monitor;
use Nowo\UptimeMonitorBundle\Entity\Tenant;
use Nowo\UptimeMonitorBundle\Enum\CheckStatus;
use Nowo\UptimeMonitorBundle\Enum\MonitorType;
use Nowo\UptimeMonitorBundle\Event\MonitorRecoveredEvent;
use Nowo\UptimeMonitorBundle\Event\MonitorWentDownEvent;
use Nowo\UptimeMonitorBundle\Model\IncidentSnapshot;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;

#[CoversClass(MonitorWentDownEvent::class)]
#[CoversClass(MonitorRecoveredEvent::class)]
#[CoversClass(IncidentSnapshot::class)]
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

    /** Since 1.25: scalar getters and a snapshot, so a host ACL never reads the entities. */
    public function testEventsExposeScalarsAndASnapshot(): void
    {
        $monitor  = new Monitor(new Tenant('main', 'Main'), 'API', MonitorType::Tcp, 'x.test:443');
        $incident = new Incident($monitor, CheckStatus::Degraded, 'slow', new DateTimeImmutable('2026-11-10 09:00:00'));
        $this->setId($monitor, 7);
        $this->setId($incident, 11);

        foreach ([new MonitorWentDownEvent($monitor, $incident, 'slow'), new MonitorRecoveredEvent($monitor, $incident)] as $event) {
            self::assertSame('main', $event->getTenantSlug());
            self::assertSame(7, $event->getMonitorId());
            self::assertSame(11, $event->getIncidentId());
            self::assertEquals(
                new IncidentSnapshot('main', 7, 'API', MonitorType::Tcp, 11, CheckStatus::Degraded, 'slow', new DateTimeImmutable('2026-11-10 09:00:00'), null),
                $event->snapshot(),
            );
        }

        $incident->resolve(new DateTimeImmutable('2026-11-10 09:05:00'));
        self::assertEquals(new DateTimeImmutable('2026-11-10 09:05:00'), (new MonitorRecoveredEvent($monitor, $incident))->snapshot()->endedAt);
    }

    private function setId(object $entity, int $id): void
    {
        (new ReflectionProperty($entity, 'id'))->setValue($entity, $id);
    }
}
