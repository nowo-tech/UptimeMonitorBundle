<?php

declare(strict_types=1);

namespace Nowo\UptimeMonitorBundle\Tests\Unit\Service;

use Doctrine\ORM\EntityManagerInterface;
use Nowo\UptimeMonitorBundle\Check\CheckRunnerInterface;
use Nowo\UptimeMonitorBundle\Entity\Monitor;
use Nowo\UptimeMonitorBundle\Entity\Tenant;
use Nowo\UptimeMonitorBundle\Enum\CheckStatus;
use Nowo\UptimeMonitorBundle\Enum\MonitorType;
use Nowo\UptimeMonitorBundle\Model\CheckResultDto;
use Nowo\UptimeMonitorBundle\Service\AggregateService;
use Nowo\UptimeMonitorBundle\Service\CheckExecutorService;
use Nowo\UptimeMonitorBundle\Service\CheckLatencyNormalizer;
use Nowo\UptimeMonitorBundle\Service\NotificationService;
use Nowo\UptimeMonitorBundle\Service\StatusTransitionService;
use Nowo\UptimeMonitorBundle\Tests\Unit\Support\SyncDispatcherTestTrait;
use PHPUnit\Framework\TestCase;

/**
 * @covers \Nowo\UptimeMonitorBundle\Service\CheckExecutorService
 */
final class CheckExecutorServiceTest extends TestCase
{
    use SyncDispatcherTestTrait;

    public function testExecuteRunsMatchingRunnerAndPersists(): void
    {
        $tenant  = new Tenant('main', 'Main');
        $monitor = new Monitor($tenant, 'API', MonitorType::Https, 'https://example.test');

        $runner = $this->createMock(CheckRunnerInterface::class);
        $runner->method('supports')->willReturn(true);
        $runner->method('run')->willReturn(new CheckResultDto(CheckStatus::Up, 42, 200));

        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects(self::atLeastOnce())->method('persist');
        $entityManager->expects(self::once())->method('flush');

        $aggregateRepository = $this->getMockBuilder(\Nowo\UptimeMonitorBundle\Repository\CheckAggregateRepository::class)
            ->onlyMethods(['findOneForBucket'])
            ->disableOriginalConstructor()
            ->getMock();
        $aggregateRepository->method('findOneForBucket')->willReturn(null);
        $aggregateService = new AggregateService($entityManager, $aggregateRepository, ['periods' => ['day']]);

        $incidentRepo        = $this->createMock(\Nowo\UptimeMonitorBundle\Repository\IncidentRepository::class);
        $notificationService = new NotificationService([], ['enabled' => false]);
        $statusTransition    = new StatusTransitionService($entityManager, $incidentRepo, $notificationService, []);

        $syncDispatcher = $this->pollingSyncDispatcher();

        $service = new CheckExecutorService(
            [$runner],
            $entityManager,
            $aggregateService,
            $statusTransition,
            $syncDispatcher,
            new CheckLatencyNormalizer(['min_latency_ms' => 0]),
        );

        $result = $service->execute($monitor);

        self::assertSame(CheckStatus::Up, $result->getStatus());
        self::assertSame(42, $result->getLatencyMs());
        self::assertNotNull($monitor->getNextCheckAt());
    }

    public function testExecuteReturnsUnknownWhenNoRunnerMatches(): void
    {
        $tenant  = new Tenant('main', 'Main');
        $monitor = new Monitor($tenant, 'X', MonitorType::Ping, '8.8.8.8');

        $runner = $this->createMock(CheckRunnerInterface::class);
        $runner->method('supports')->willReturn(false);

        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects(self::once())->method('persist');
        $entityManager->expects(self::once())->method('flush');

        $aggregateRepository = $this->getMockBuilder(\Nowo\UptimeMonitorBundle\Repository\CheckAggregateRepository::class)
            ->onlyMethods(['findOneForBucket'])
            ->disableOriginalConstructor()
            ->getMock();
        $aggregateRepository->method('findOneForBucket')->willReturn(null);
        $aggregateService = new AggregateService($entityManager, $aggregateRepository, ['periods' => []]);
        $statusTransition = new StatusTransitionService(
            $entityManager,
            $this->createMock(\Nowo\UptimeMonitorBundle\Repository\IncidentRepository::class),
            new NotificationService([], ['enabled' => false]),
            [],
        );

        $syncDispatcher = $this->pollingSyncDispatcher();

        $service = new CheckExecutorService(
            [$runner],
            $entityManager,
            $aggregateService,
            $statusTransition,
            $syncDispatcher,
            new CheckLatencyNormalizer(['min_latency_ms' => 0]),
        );

        $result = $service->execute($monitor);

        self::assertSame(CheckStatus::Unknown, $result->getStatus());
        self::assertStringContainsString('No check runner', (string) $result->getMessage());
    }

    public function testExecuteAppliesMinimumLatencyFloor(): void
    {
        $tenant  = new Tenant('main', 'Main');
        $monitor = new Monitor($tenant, 'API', MonitorType::Https, 'https://example.test');

        $runner = $this->createMock(CheckRunnerInterface::class);
        $runner->method('supports')->willReturn(true);
        $runner->method('run')->willReturn(new CheckResultDto(CheckStatus::Up, 0, 200));

        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects(self::atLeastOnce())->method('persist');
        $entityManager->expects(self::once())->method('flush');

        $aggregateRepository = $this->getMockBuilder(\Nowo\UptimeMonitorBundle\Repository\CheckAggregateRepository::class)
            ->onlyMethods(['findOneForBucket'])
            ->disableOriginalConstructor()
            ->getMock();
        $aggregateRepository->method('findOneForBucket')->willReturn(null);
        $aggregateService = new AggregateService($entityManager, $aggregateRepository, ['periods' => []]);
        $statusTransition = new StatusTransitionService(
            $entityManager,
            $this->createMock(\Nowo\UptimeMonitorBundle\Repository\IncidentRepository::class),
            new NotificationService([], ['enabled' => false]),
            [],
        );

        $service = new CheckExecutorService(
            [$runner],
            $entityManager,
            $aggregateService,
            $statusTransition,
            $this->pollingSyncDispatcher(),
            new CheckLatencyNormalizer(['min_latency_ms' => 3]),
        );

        self::assertSame(3, $service->execute($monitor)->getLatencyMs());
    }
}
