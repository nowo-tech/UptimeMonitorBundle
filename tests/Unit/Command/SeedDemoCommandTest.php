<?php

declare(strict_types=1);

namespace Nowo\UptimeMonitorBundle\Tests\Unit\Command;

use Doctrine\ORM\EntityManagerInterface;
use Nowo\UptimeMonitorBundle\Command\SeedDemoCommand;
use Nowo\UptimeMonitorBundle\Entity\Tenant;
use Nowo\UptimeMonitorBundle\Repository\MonitorRepository;
use Nowo\UptimeMonitorBundle\Repository\TenantRepository;
use Nowo\UptimeMonitorBundle\Service\DemoSeedService;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * @covers \Nowo\UptimeMonitorBundle\Command\SeedDemoCommand
 */
final class SeedDemoCommandTest extends TestCase
{
    public function testExecuteReportsCreatedMonitors(): void
    {
        $tenant = new Tenant('acme', 'Acme');

        $tenantRepo = $this->getMockBuilder(TenantRepository::class)
            ->onlyMethods(['findOneBySlug'])
            ->disableOriginalConstructor()
            ->getMock();
        $tenantRepo->method('findOneBySlug')->willReturn($tenant);

        $monitorRepo = $this->getMockBuilder(MonitorRepository::class)
            ->onlyMethods(['findOneBy'])
            ->disableOriginalConstructor()
            ->getMock();
        $monitorRepo->method('findOneBy')->willReturn(null);

        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('persist');
        $em->method('flush');

        $command = new SeedDemoCommand(new DemoSeedService($em, $tenantRepo, $monitorRepo));
        $tester  = new CommandTester($command);
        $tester->execute(['--tenant' => 'acme', '--name' => 'Acme Corp']);

        self::assertSame(0, $tester->getStatusCode());
        self::assertStringContainsString('acme', $tester->getDisplay());
    }
}
