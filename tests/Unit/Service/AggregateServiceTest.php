<?php

declare(strict_types=1);

namespace Nowo\UptimeMonitorBundle\Tests\Unit\Service;

use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Nowo\UptimeMonitorBundle\Entity\CheckAggregate;
use Nowo\UptimeMonitorBundle\Entity\Monitor;
use Nowo\UptimeMonitorBundle\Entity\Tenant;
use Nowo\UptimeMonitorBundle\Enum\AggregatePeriod;
use Nowo\UptimeMonitorBundle\Enum\CheckStatus;
use Nowo\UptimeMonitorBundle\Enum\MonitorType;
use Nowo\UptimeMonitorBundle\Model\CheckResultDto;
use Nowo\UptimeMonitorBundle\Repository\CheckAggregateRepository;
use Nowo\UptimeMonitorBundle\Service\AggregateService;
use Nowo\UptimeMonitorBundle\Tests\Unit\Support\DoctrineQueryBuilderTrait;
use PHPUnit\Framework\TestCase;

/**
 * @covers \Nowo\UptimeMonitorBundle\Service\AggregateService
 */
final class AggregateServiceTest extends TestCase
{
    use DoctrineQueryBuilderTrait;

    public function testRecordFromCheckCreatesNewAggregate(): void
    {
        $tenant  = new Tenant('main', 'Main');
        $monitor = new Monitor($tenant, 'API', MonitorType::Https, 'https://example.test');
        $dto     = new CheckResultDto(CheckStatus::Up, 50, 200);

        $registry            = $this->createManagerRegistryWithQueryResult([]);
        $aggregateRepository = $this->getMockBuilder(CheckAggregateRepository::class)
            ->onlyMethods(['findOneForBucket'])
            ->setConstructorArgs([$registry])
            ->getMock();
        $aggregateRepository->method('findOneForBucket')->willReturn(null);

        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects(self::atLeastOnce())->method('persist')
            ->with(self::callback(static fn ($entity): bool => $entity instanceof CheckAggregate));

        $service = new AggregateService($entityManager, $aggregateRepository, [
            'periods' => ['hour', 'day', 'invalid', 'month', 'year'],
        ]);

        $service->recordFromCheck($monitor, $dto);
    }

    public function testRecordFromCheckUpdatesExistingAggregate(): void
    {
        $tenant   = new Tenant('main', 'Main');
        $monitor  = new Monitor($tenant, 'API', MonitorType::Https, 'https://example.test');
        $existing = new CheckAggregate($monitor, AggregatePeriod::Day, new DateTimeImmutable());
        $dto      = new CheckResultDto(CheckStatus::Down, 100, 503);

        $registry            = $this->createManagerRegistryWithQueryResult([]);
        $aggregateRepository = $this->getMockBuilder(CheckAggregateRepository::class)
            ->onlyMethods(['findOneForBucket'])
            ->setConstructorArgs([$registry])
            ->getMock();
        $aggregateRepository->method('findOneForBucket')->willReturn($existing);

        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects(self::never())->method('persist');

        $service = new AggregateService($entityManager, $aggregateRepository, ['periods' => ['day']]);
        $service->recordFromCheck($monitor, $dto);

        self::assertSame(1, $existing->getChecksTotal());
    }
}
