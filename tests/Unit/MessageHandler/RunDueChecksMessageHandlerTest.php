<?php

declare(strict_types=1);

namespace Nowo\UptimeMonitorBundle\Tests\Unit\MessageHandler;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Mapping\ClassMetadata;
use Doctrine\Persistence\ManagerRegistry;
use Nowo\UptimeMonitorBundle\Entity\Monitor;
use Nowo\UptimeMonitorBundle\Message\RunDueChecksMessage;
use Nowo\UptimeMonitorBundle\MessageHandler\RunDueChecksMessageHandler;
use Nowo\UptimeMonitorBundle\Repository\MonitorRepository;
use Nowo\UptimeMonitorBundle\Service\DueChecksRunner;
use Nowo\UptimeMonitorBundle\Tests\Unit\Support\DoctrineQueryBuilderTrait;
use Nowo\UptimeMonitorBundle\Tests\Unit\Support\SyncDispatcherTestTrait;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

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

        $this->addToAssertionCount(1);
    }

    public function testSuccessIsLoggedAtDebugWithTheExecutedCount(): void
    {
        $em     = $this->createMock(EntityManagerInterface::class);
        $runner = new DueChecksRunner(
            new MonitorRepository($this->createManagerRegistryWithQueryResult([])),
            $this->checkExecutorService([], $em),
        );

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())->method('debug')->with(
            self::stringContains('due-check scan'),
            self::callback(static fn (array $context): bool => $context['executed'] === 0 && $context['message'] === RunDueChecksMessage::class),
        );
        $logger->expects(self::never())->method('error');

        (new RunDueChecksMessageHandler($runner, $logger))(new RunDueChecksMessage());
    }

    public function testFailureIsLoggedAndRethrownForMessengerRetry(): void
    {
        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('createQueryBuilder')->willThrowException(new RuntimeException('SQLSTATE[HY000] secret dsn'));
        $em->method('getClassMetadata')->willReturn(new ClassMetadata(Monitor::class));
        $registry = $this->createMock(ManagerRegistry::class);
        $registry->method('getManager')->willReturn($em);
        $registry->method('getManagerForClass')->willReturn($em);

        $runner = new DueChecksRunner(new MonitorRepository($registry), $this->checkExecutorService([], $em));

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())->method('error')->with(
            self::anything(),
            self::callback(static fn (array $context): bool => $context['error'] === RuntimeException::class
                && !str_contains((string) json_encode($context), 'secret')),
        );

        $this->expectException(RuntimeException::class);

        (new RunDueChecksMessageHandler($runner, $logger))(new RunDueChecksMessage());
    }
}
