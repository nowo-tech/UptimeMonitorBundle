<?php

declare(strict_types=1);

namespace Nowo\UptimeMonitorBundle\Tests\Unit\Command;

use Doctrine\ORM\EntityManagerInterface;
use Nowo\UptimeMonitorBundle\Command\RunDueChecksCommand;
use Nowo\UptimeMonitorBundle\Repository\IncidentRepository;
use Nowo\UptimeMonitorBundle\Repository\MonitorRepository;
use Nowo\UptimeMonitorBundle\Service\AggregateService;
use Nowo\UptimeMonitorBundle\Service\CheckExecutorService;
use Nowo\UptimeMonitorBundle\Service\DueChecksRunner;
use Nowo\UptimeMonitorBundle\Service\NotificationService;
use Nowo\UptimeMonitorBundle\Service\StatusTransitionService;
use Nowo\UptimeMonitorBundle\Tests\Unit\Support\SyncDispatcherTestTrait;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * @covers \Nowo\UptimeMonitorBundle\Command\RunDueChecksCommand
 */
final class RunDueChecksCommandTest extends TestCase
{
    use SyncDispatcherTestTrait;

    public function testExecuteReportsCount(): void
    {
        $monitorRepository = $this->createMock(MonitorRepository::class);
        $monitorRepository->method('findDue')->willReturn([]);

        $em                  = $this->createMock(EntityManagerInterface::class);
        $aggregateRepository = $this->getMockBuilder(\Nowo\UptimeMonitorBundle\Repository\CheckAggregateRepository::class)
            ->onlyMethods(['findOneForBucket'])
            ->disableOriginalConstructor()
            ->getMock();

        $runner = new DueChecksRunner(
            $monitorRepository,
            new CheckExecutorService(
                [],
                $em,
                new AggregateService($em, $aggregateRepository, ['periods' => []]),
                new StatusTransitionService($em, $this->createMock(IncidentRepository::class), new NotificationService([], []), []),
                $this->pollingSyncDispatcher(),
            ),
        );

        $command = new RunDueChecksCommand($runner);
        $tester  = new CommandTester($command);
        $tester->execute([]);

        self::assertSame(0, $tester->getStatusCode());
        self::assertStringContainsString('0', $tester->getDisplay());
    }
}
