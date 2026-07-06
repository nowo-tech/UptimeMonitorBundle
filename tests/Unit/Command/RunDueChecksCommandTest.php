<?php

declare(strict_types=1);

namespace Nowo\UptimeMonitorBundle\Tests\Unit\Command;

use Nowo\UptimeMonitorBundle\Command\RunDueChecksCommand;
use Nowo\UptimeMonitorBundle\Repository\MonitorRepository;
use Nowo\UptimeMonitorBundle\Service\DueChecksRunner;
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

        $runner = new DueChecksRunner(
            $monitorRepository,
            $this->checkExecutorService(),
        );

        $command = new RunDueChecksCommand($runner);
        $tester  = new CommandTester($command);
        $tester->execute([]);

        self::assertSame(0, $tester->getStatusCode());
        self::assertStringContainsString('0', $tester->getDisplay());
    }
}
