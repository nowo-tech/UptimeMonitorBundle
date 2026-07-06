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
use Nowo\UptimeMonitorBundle\Repository\IncidentRepository;
use Nowo\UptimeMonitorBundle\Repository\MonitorRepository;
use Nowo\UptimeMonitorBundle\Service\AggregateService;
use Nowo\UptimeMonitorBundle\Service\CheckExecutorService;
use Nowo\UptimeMonitorBundle\Service\DueChecksRunner;
use Nowo\UptimeMonitorBundle\Service\NotificationService;
use Nowo\UptimeMonitorBundle\Service\StatusTransitionService;
use Nowo\UptimeMonitorBundle\Tests\Unit\Support\SyncDispatcherTestTrait;
use PHPUnit\Framework\TestCase;

/**
 * @covers \Nowo\UptimeMonitorBundle\Service\DueChecksRunner
 */
final class DueChecksRunnerTest extends TestCase
{
    use SyncDispatcherTestTrait;

    public function testRunDueChecksExecutesEachMonitor(): void
    {
        $monitor = new Monitor(new Tenant('main', 'Main'), 'API', MonitorType::Https, 'https://example.test');

        $monitorRepository = $this->createMock(MonitorRepository::class);
        $monitorRepository->method('findDue')->willReturn([$monitor]);

        $runner = $this->createMock(CheckRunnerInterface::class);
        $runner->method('supports')->willReturn(true);
        $runner->method('run')->willReturn(new CheckResultDto(CheckStatus::Up, 1, 200));

        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('persist');
        $em->method('flush');

        $aggregateRepository = $this->getMockBuilder(\Nowo\UptimeMonitorBundle\Repository\CheckAggregateRepository::class)
            ->onlyMethods(['findOneForBucket'])
            ->disableOriginalConstructor()
            ->getMock();
        $aggregateRepository->method('findOneForBucket')->willReturn(null);

        $executor = new CheckExecutorService(
            [$runner],
            $em,
            new AggregateService($em, $aggregateRepository, ['periods' => []]),
            new StatusTransitionService($em, $this->createMock(IncidentRepository::class), new NotificationService([], []), []),
            $this->pollingSyncDispatcher(),
        );

        $dueRunner = new DueChecksRunner($monitorRepository, $executor);

        self::assertSame(1, $dueRunner->runDueChecks());
    }

    public function testRunDueChecksReturnsZeroWhenNoneDue(): void
    {
        $monitorRepository = $this->createMock(MonitorRepository::class);
        $monitorRepository->method('findDue')->willReturn([]);

        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects(self::never())->method('flush');

        $aggregateRepository = $this->getMockBuilder(\Nowo\UptimeMonitorBundle\Repository\CheckAggregateRepository::class)
            ->onlyMethods(['findOneForBucket'])
            ->disableOriginalConstructor()
            ->getMock();

        $executor = new CheckExecutorService(
            [],
            $em,
            new AggregateService($em, $aggregateRepository, ['periods' => []]),
            new StatusTransitionService($em, $this->createMock(IncidentRepository::class), new NotificationService([], []), []),
            $this->pollingSyncDispatcher(),
        );

        $dueRunner = new DueChecksRunner($monitorRepository, $executor);

        self::assertSame(0, $dueRunner->runDueChecks());
    }
}
