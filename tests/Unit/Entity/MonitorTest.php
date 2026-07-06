<?php

declare(strict_types=1);

namespace Nowo\UptimeMonitorBundle\Tests\Unit\Entity;

use DateTimeImmutable;
use Nowo\UptimeMonitorBundle\Entity\Monitor;
use Nowo\UptimeMonitorBundle\Entity\Tenant;
use Nowo\UptimeMonitorBundle\Enum\CheckStatus;
use Nowo\UptimeMonitorBundle\Enum\MonitorType;
use Nowo\UptimeMonitorBundle\Tests\Unit\Support\EntityIdTrait;
use PHPUnit\Framework\TestCase;

/**
 * @covers \Nowo\UptimeMonitorBundle\Entity\Monitor
 */
final class MonitorTest extends TestCase
{
    use EntityIdTrait;

    public function testGetIdReturnsAssignedValue(): void
    {
        $monitor = new Monitor(new Tenant('main', 'Main'), 'API', MonitorType::Https, 'https://x.test');
        $this->setEntityId($monitor, 99);

        self::assertSame(99, $monitor->getId());
    }

    public function testGettersAndSetters(): void
    {
        $tenant  = new Tenant('main', 'Main');
        $monitor = new Monitor($tenant, 'API', MonitorType::Https, 'https://example.test');

        $monitor
            ->setName('Renamed')
            ->setType(MonitorType::Http)
            ->setTarget('http://example.test')
            ->setConfig(['url' => 'http://example.test'])
            ->setIntervalSeconds(120)
            ->setPaused(true)
            ->setLastKnownStatus(CheckStatus::Down)
            ->setLastAlertAt(new DateTimeImmutable('2026-01-01'))
            ->setNextCheckAt(new DateTimeImmutable('2026-01-02'));

        self::assertSame($tenant, $monitor->getTenant());
        self::assertSame('Renamed', $monitor->getName());
        self::assertSame(MonitorType::Http, $monitor->getType());
        self::assertSame('http://example.test', $monitor->getTarget());
        self::assertSame(['url' => 'http://example.test'], $monitor->getConfig());
        self::assertSame(120, $monitor->getIntervalSeconds());
        self::assertTrue($monitor->isPaused());
        self::assertSame(CheckStatus::Down, $monitor->getLastKnownStatus());
        self::assertNotNull($monitor->getLastAlertAt());
        self::assertNotNull($monitor->getNextCheckAt());
    }

    public function testScheduleNextCheckAdvancesNextCheckAt(): void
    {
        $monitor = new Monitor(new Tenant('main', 'Main'), 'API', MonitorType::Https, 'https://x.test');
        $monitor->setIntervalSeconds(60);
        $from = new DateTimeImmutable('2026-05-01 12:00:00');

        $monitor->scheduleNextCheck($from);

        self::assertSame('2026-05-01 12:01:00', $monitor->getNextCheckAt()?->format('Y-m-d H:i:s'));
    }
}
