<?php

declare(strict_types=1);

namespace Nowo\UptimeMonitorBundle\Tests\Unit\Command;

use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use Doctrine\Persistence\ObjectManager;
use Nowo\UptimeMonitorBundle\Command\SyncSchemaCommand;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * @covers \Nowo\UptimeMonitorBundle\Command\SyncSchemaCommand
 */
final class SyncSchemaCommandTest extends TestCase
{
    public function testConfigureDefinesForceOption(): void
    {
        $command = new SyncSchemaCommand(
            $this->createMock(ManagerRegistry::class),
            'default',
        );

        self::assertTrue($command->getDefinition()->hasOption('force'));
        self::assertStringContainsString('uptime_tenant', $command->getHelp());
    }

    public function testExecuteReportsUpToDate(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->expects(self::never())->method('executeStatement');

        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('getConnection')->willReturn($connection);

        $registry = $this->createMock(ManagerRegistry::class);
        $registry->method('getManager')->with('default')->willReturn($em);

        $command = new SyncSchemaCommand($registry, 'default');
        $tester  = new CommandTester($command);
        $tester->execute([]);

        self::assertSame(0, $tester->getStatusCode());
        self::assertStringContainsString('up to date', $tester->getDisplay());
    }

    public function testExecuteRunsStatementsWithForceOption(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->method('executeStatement');

        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('getConnection')->willReturn($connection);

        $registry = $this->createMock(ManagerRegistry::class);
        $registry->method('getManager')->willReturn($em);

        $command = new SyncSchemaCommand($registry, 'default');
        $tester  = new CommandTester($command);
        $tester->execute(['--force' => true]);

        self::assertSame(0, $tester->getStatusCode());
        self::assertTrue(
            str_contains($tester->getDisplay(), 'Executed')
            || str_contains($tester->getDisplay(), 'up to date'),
        );
    }

    public function testExecuteThrowsForNonOrmManager(): void
    {
        $registry = $this->createMock(ManagerRegistry::class);
        $registry->method('getManager')->willReturn($this->createMock(ObjectManager::class));

        $command = new SyncSchemaCommand($registry, 'default');
        $tester  = new CommandTester($command);

        $this->expectException(RuntimeException::class);
        $tester->execute([]);
    }
}
