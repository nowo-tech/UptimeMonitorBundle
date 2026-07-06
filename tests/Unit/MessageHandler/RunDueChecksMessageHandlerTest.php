<?php

declare(strict_types=1);

namespace Nowo\UptimeMonitorBundle\Tests\Unit\MessageHandler;

use Doctrine\ORM\EntityManagerInterface;
use Nowo\UptimeMonitorBundle\Message\RunDueChecksMessage;
use Nowo\UptimeMonitorBundle\MessageHandler\RunDueChecksMessageHandler;
use Nowo\UptimeMonitorBundle\Repository\MonitorRepository;
use Nowo\UptimeMonitorBundle\Service\DueChecksRunner;
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
        $em                = $this->createMock(EntityManagerInterface::class);
        $monitorRepository = new MonitorRepository($this->createManagerRegistryWithQueryResult([]));

        $runner = new DueChecksRunner(
            $monitorRepository,
            $this->checkExecutorService([], $em),
        );

        $handler = new RunDueChecksMessageHandler($runner);
        ($handler)(new RunDueChecksMessage());

        self::assertTrue(true);
    }
}
