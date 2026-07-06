<?php

declare(strict_types=1);

namespace Nowo\UptimeMonitorBundle\Tests\Unit\Entity;

use DateTimeImmutable;
use Nowo\UptimeMonitorBundle\Entity\Incident;
use Nowo\UptimeMonitorBundle\Entity\Monitor;
use Nowo\UptimeMonitorBundle\Entity\Tenant;
use Nowo\UptimeMonitorBundle\Enum\CheckStatus;
use Nowo\UptimeMonitorBundle\Enum\MonitorType;
use PHPUnit\Framework\TestCase;

/**
 * @covers \Nowo\UptimeMonitorBundle\Entity\Incident
 */
final class IncidentTest extends TestCase
{
    public function testResolveClosesIncident(): void
    {
        $monitor  = new Monitor(new Tenant('main', 'Main'), 'API', MonitorType::Https, 'https://x.test');
        $incident = new Incident($monitor, CheckStatus::Down, 'timeout');

        self::assertTrue($incident->isOpen());
        self::assertSame($monitor, $incident->getMonitor());
        self::assertInstanceOf(DateTimeImmutable::class, $incident->getStartedAt());

        $ended = new DateTimeImmutable('2026-05-02');
        $incident->resolve($ended);

        self::assertFalse($incident->isOpen());
        self::assertSame($ended, $incident->getEndedAt());
    }
}
