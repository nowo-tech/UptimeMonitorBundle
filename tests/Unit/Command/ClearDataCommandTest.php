<?php

declare(strict_types=1);

namespace Nowo\UptimeMonitorBundle\Tests\Unit\Command;

use Nowo\UptimeMonitorBundle\Command\ClearDataCommand;
use Nowo\UptimeMonitorBundle\Service\DashboardSyncDispatcher;
use Nowo\UptimeMonitorBundle\Service\UptimeDataClearService;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * @covers \Nowo\UptimeMonitorBundle\Command\ClearDataCommand
 */
final class ClearDataCommandTest extends TestCase
{
    public function testExecuteClearsWithNoInteraction(): void
    {
        $service = $this->createMock(UptimeDataClearService::class);
        $service->method('clear')->with(null)->willReturn([
            'checks'         => 10,
            'aggregates'     => 4,
            'incidents'      => 1,
            'monitors_reset' => 3,
        ]);

        $sync = $this->createMock(DashboardSyncDispatcher::class);
        $sync->expects(self::once())->method('dispatchTenantRefresh');

        $service->method('listTenantSlugs')->willReturn(['main']);

        $tester = new CommandTester(new ClearDataCommand($service, $sync));
        $tester->execute(['--no-interaction' => true]);

        self::assertSame(0, $tester->getStatusCode());
        self::assertStringContainsString('10 check', $tester->getDisplay());
    }
}
