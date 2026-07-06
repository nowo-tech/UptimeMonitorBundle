<?php

declare(strict_types=1);

namespace Nowo\UptimeMonitorBundle\Tests\Unit\Command;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Query;
use Doctrine\ORM\QueryBuilder;
use Nowo\UptimeMonitorBundle\Command\ClearDataCommand;
use Nowo\UptimeMonitorBundle\Repository\MonitorRepository;
use Nowo\UptimeMonitorBundle\Repository\TenantRepository;
use Nowo\UptimeMonitorBundle\Service\DashboardSyncDispatcher;
use Nowo\UptimeMonitorBundle\Service\UptimeDataClearService;
use Nowo\UptimeMonitorBundle\Tests\Unit\Support\SyncDispatcherTestTrait;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * @covers \Nowo\UptimeMonitorBundle\Command\ClearDataCommand
 */
final class ClearDataCommandTest extends TestCase
{
    use SyncDispatcherTestTrait;

    public function testExecuteClearsWithNoInteraction(): void
    {
        $query = $this->createMock(Query::class);
        $query->method('execute')->willReturn(10);

        $qb = $this->createMock(QueryBuilder::class);
        $qb->method('delete')->willReturnSelf();
        $qb->method('andWhere')->willReturnSelf();
        $qb->method('setParameter')->willReturnSelf();
        $qb->method('getQuery')->willReturn($query);

        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('createQueryBuilder')->willReturn($qb);
        $em->method('flush');

        $tenantRepo = $this->createMock(TenantRepository::class);
        $tenantRepo->method('findAll')->willReturn([]);

        $monitorRepo = $this->createMock(MonitorRepository::class);
        $monitorRepo->method('findAll')->willReturn([]);

        $service = new UptimeDataClearService($em, $tenantRepo, $monitorRepo);
        $sync    = $this->pollingSyncDispatcher();

        $tester = new CommandTester(new ClearDataCommand($service, $sync));
        $tester->execute(['--no-interaction' => true]);

        self::assertSame(0, $tester->getStatusCode());
        self::assertStringContainsString('10 check', $tester->getDisplay());
    }
}
