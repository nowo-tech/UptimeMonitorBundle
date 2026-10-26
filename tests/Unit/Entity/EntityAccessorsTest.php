<?php

declare(strict_types=1);

namespace Nowo\UptimeMonitorBundle\Tests\Unit\Entity;

use DateTimeImmutable;
use Nowo\UptimeMonitorBundle\Entity\CheckAggregate;
use Nowo\UptimeMonitorBundle\Entity\CheckResult;
use Nowo\UptimeMonitorBundle\Entity\Incident;
use Nowo\UptimeMonitorBundle\Entity\Monitor;
use Nowo\UptimeMonitorBundle\Entity\Tag;
use Nowo\UptimeMonitorBundle\Entity\Tenant;
use Nowo\UptimeMonitorBundle\Enum\AggregatePeriod;
use Nowo\UptimeMonitorBundle\Enum\CheckStatus;
use Nowo\UptimeMonitorBundle\Enum\MonitorType;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(Monitor::class)]
#[CoversClass(Tenant::class)]
#[CoversClass(Tag::class)]
#[CoversClass(CheckAggregate::class)]
#[CoversClass(CheckResult::class)]
#[CoversClass(Incident::class)]
final class EntityAccessorsTest extends TestCase
{
    public function testMonitorProjectParentAndRetryInterval(): void
    {
        $tenant  = new Tenant('main', 'Main');
        $group   = new Monitor($tenant, 'Payments', MonitorType::Group, 'Payments');
        $monitor = new Monitor($tenant, 'API', MonitorType::Https, 'https://api.test');

        self::assertTrue($group->isGroup());
        self::assertFalse($monitor->isGroup());
        self::assertNull($monitor->getId());

        $monitor->setProject('  Shop  ');
        self::assertSame('Shop', $monitor->getProject());
        $monitor->setProject('   ');
        self::assertNull($monitor->getProject());
        $monitor->setProject(null);
        self::assertNull($monitor->getProject());

        $monitor->setParent($group);
        self::assertSame($group, $monitor->getParent());
        self::assertSame('Payments', $monitor->getProject());
        $monitor->setParent(null);
        self::assertNull($monitor->getParent());

        self::assertSame(60, $monitor->getRetryIntervalSeconds());
        $monitor->setRetryIntervalSeconds(5);
        self::assertSame(30, $monitor->getRetryIntervalSeconds());
        $monitor->setConfig(['retry_interval_seconds' => 'often']);
        self::assertSame(60, $monitor->getRetryIntervalSeconds());

        self::assertLessThanOrEqual(new DateTimeImmutable(), $monitor->getCreatedAt());
    }

    public function testTenantAccessors(): void
    {
        $tenant = new Tenant('main', 'Main');

        self::assertNull($tenant->getId());
        self::assertSame([], $tenant->getSettings());
        self::assertSame(['a' => 1], $tenant->setSettings(['a' => 1])->getSettings());
        self::assertLessThanOrEqual(new DateTimeImmutable(), $tenant->getCreatedAt());
    }

    public function testTagAccessors(): void
    {
        $tenant = new Tenant('main', 'Main');
        $tag    = new Tag($tenant, '  prod ');

        self::assertNull($tag->getId());
        self::assertSame($tenant, $tag->getTenant());
        self::assertSame('prod', $tag->getName());
        self::assertSame('eu', $tag->setName(' eu ')->getName());
        self::assertNull($tag->getColor());
        self::assertSame('#16a34a', $tag->setColor('#16a34a')->getColor());
        self::assertNull($tag->setColor('')->getColor());
    }

    public function testAggregateTotalsAndRatios(): void
    {
        $monitor   = new Monitor(new Tenant('main', 'Main'), 'API', MonitorType::Https, 'https://api.test');
        $aggregate = new CheckAggregate($monitor, AggregatePeriod::Day, new DateTimeImmutable('2026-01-01'));

        self::assertNull($aggregate->getId());
        $aggregate->applyTotals(4, 3, 25);
        self::assertSame(4, $aggregate->getChecksTotal());
        self::assertSame(3, $aggregate->getChecksUp());
        self::assertSame(1, $aggregate->getChecksDown());
        self::assertSame(0.75, $aggregate->getUptimeRatio());
        self::assertSame(25, $aggregate->getLatencyAvgMs());

        $aggregate->applyTotals(0, 0, 0);
        self::assertSame(0.0, $aggregate->getUptimeRatio());
    }

    public function testResultAndIncidentIdsAreNullUntilPersisted(): void
    {
        $monitor = new Monitor(new Tenant('main', 'Main'), 'API', MonitorType::Https, 'https://api.test');

        self::assertNull((new CheckResult($monitor, CheckStatus::Up, 1))->getId());
        self::assertNull((new Incident($monitor, CheckStatus::Down))->getId());
    }
}
