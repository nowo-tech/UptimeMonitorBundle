<?php

declare(strict_types=1);

namespace Nowo\UptimeMonitorBundle\Tests\Unit\Command;

use DateTimeImmutable;
use Nowo\UptimeMonitorBundle\Command\ClearDataCommand;
use Nowo\UptimeMonitorBundle\Entity\Monitor;
use Nowo\UptimeMonitorBundle\Entity\Tenant;
use Nowo\UptimeMonitorBundle\Enum\MonitorType;
use Nowo\UptimeMonitorBundle\Repository\MonitorRepository;
use Nowo\UptimeMonitorBundle\Repository\TenantRepository;
use Nowo\UptimeMonitorBundle\Service\UptimeDataClearService;
use Nowo\UptimeMonitorBundle\Tests\Support\SqliteEntityManagerFactory;
use Nowo\UptimeMonitorBundle\Tests\Unit\Support\SyncDispatcherTestTrait;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;

#[CoversClass(ClearDataCommand::class)]
final class ClearDataCommandTest extends TestCase
{
    use SyncDispatcherTestTrait;

    public function testExecuteClearsWithNoInteraction(): void
    {
        $em      = SqliteEntityManagerFactory::create();
        $tenant  = new Tenant('main', 'Main');
        $monitor = new Monitor($tenant, 'API', MonitorType::Https, 'https://example.test');
        $em->persist($tenant);
        $em->persist($monitor);
        $em->flush();
        SqliteEntityManagerFactory::addCheckResults($em, $monitor, 10, new DateTimeImmutable('-1 day'));

        $tenantRepo = $this->createMock(TenantRepository::class);
        $tenantRepo->method('findAll')->willReturn([]);

        $monitorRepo = $this->createMock(MonitorRepository::class);
        $monitorRepo->method('findAll')->willReturn([]);

        $service = new UptimeDataClearService($em, $tenantRepo, $monitorRepo, ['purge_batch_size' => 3]);
        $sync    = $this->pollingSyncDispatcher();

        $tester = new CommandTester(new ClearDataCommand($service, $sync));
        $tester->execute(['--no-interaction' => true]);

        self::assertSame(0, $tester->getStatusCode());
        self::assertStringContainsString('10 check', $tester->getDisplay());
    }
}
