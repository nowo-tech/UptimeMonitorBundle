<?php

declare(strict_types=1);

namespace Nowo\UptimeMonitorBundle\Tests\Unit\MessageHandler;

use Doctrine\ORM\EntityManagerInterface;
use Nowo\UptimeMonitorBundle\Message\RunDueChecksMessage;
use Nowo\UptimeMonitorBundle\MessageHandler\RunDueChecksMessageHandler;
use Nowo\UptimeMonitorBundle\Repository\IncidentRepository;
use Nowo\UptimeMonitorBundle\Repository\MonitorRepository;
use Nowo\UptimeMonitorBundle\Service\AggregateService;
use Nowo\UptimeMonitorBundle\Service\CheckExecutorService;
use Nowo\UptimeMonitorBundle\Service\DueChecksRunner;
use Nowo\UptimeMonitorBundle\Service\NotificationService;
use Nowo\UptimeMonitorBundle\Service\StatusTransitionService;
use Nowo\UptimeMonitorBundle\Tests\Unit\Support\DoctrineQueryBuilderTrait;
use Nowo\UptimeMonitorBundle\Tests\Unit\Support\SyncDispatcherTestTrait;
use PHPUnit\Framework\TestCase;

/**
 * @covers \Nowo\UptimeMonitorBundle\MessageHandler\RunDueChecksMessageHandler
 */
final class RunDueChecksMessageHandlerTest extends TestCase
{
    use DoctrineQueryBuilderTrait;

    use SyncDispatcherTestTrait;

    public function testInvokeDelegatesToRunner(): void
    {
        $em                  = $this->createMock(EntityManagerInterface::class);
        $monitorRepository   = new MonitorRepository($this->createManagerRegistryWithQueryResult([]));
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

        $handler = new RunDueChecksMessageHandler($runner);
        ($handler)(new RunDueChecksMessage());

        self::assertTrue(true);
    }
}
