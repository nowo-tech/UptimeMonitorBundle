<?php

declare(strict_types=1);

namespace Nowo\UptimeMonitorBundle\Tests\Unit\Repository;

use DateTimeImmutable;
use Nowo\UptimeMonitorBundle\Entity\CheckAggregate;
use Nowo\UptimeMonitorBundle\Entity\Monitor;
use Nowo\UptimeMonitorBundle\Entity\Tenant;
use Nowo\UptimeMonitorBundle\Enum\AggregatePeriod;
use Nowo\UptimeMonitorBundle\Enum\MonitorType;
use Nowo\UptimeMonitorBundle\Repository\CheckAggregateRepository;
use Nowo\UptimeMonitorBundle\Tests\Unit\Support\DoctrineQueryBuilderTrait;
use PHPUnit\Framework\TestCase;

/**
 * @covers \Nowo\UptimeMonitorBundle\Repository\CheckAggregateRepository
 */
final class CheckAggregateRepositoryTest extends TestCase
{
    use DoctrineQueryBuilderTrait;

    public function testFindOneForBucket(): void
    {
        $monitor   = new Monitor(new Tenant('main', 'Main'), 'API', MonitorType::Https, 'https://x.test');
        $start     = new DateTimeImmutable('2026-05-01');
        $aggregate = new CheckAggregate($monitor, AggregatePeriod::Day, $start);

        $repository = $this->getMockBuilder(CheckAggregateRepository::class)
            ->onlyMethods(['findOneBy'])
            ->disableOriginalConstructor()
            ->getMock();
        $repository->method('findOneBy')->willReturn($aggregate);

        self::assertSame(
            $aggregate,
            $repository->findOneForBucket($monitor, AggregatePeriod::Day, $start),
        );
    }

    public function testFindForMonitorInRange(): void
    {
        $monitor    = new Monitor(new Tenant('main', 'Main'), 'API', MonitorType::Https, 'https://x.test');
        $aggregate  = new CheckAggregate($monitor, AggregatePeriod::Hour, new DateTimeImmutable());
        $registry   = $this->createManagerRegistryWithQueryResult([$aggregate], CheckAggregate::class);
        $repository = new CheckAggregateRepository($registry);

        $rows = $repository->findForMonitorInRange(
            $monitor,
            AggregatePeriod::Hour,
            new DateTimeImmutable('-7 days'),
            new DateTimeImmutable(),
        );

        self::assertSame([$aggregate], $rows);
    }

    public function testFindTenantOverview(): void
    {
        $monitor    = new Monitor(new Tenant('main', 'Main'), 'API', MonitorType::Https, 'https://x.test');
        $aggregate  = new CheckAggregate($monitor, AggregatePeriod::Day, new DateTimeImmutable());
        $registry   = $this->createManagerRegistryWithQueryResult([$aggregate], CheckAggregate::class);
        $repository = new CheckAggregateRepository($registry);

        $rows = $repository->findTenantOverview(
            'main',
            AggregatePeriod::Day,
            new DateTimeImmutable('-7 days'),
            new DateTimeImmutable(),
        );

        self::assertSame([$aggregate], $rows);
    }
}
