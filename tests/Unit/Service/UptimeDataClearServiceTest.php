<?php

declare(strict_types=1);

namespace Nowo\UptimeMonitorBundle\Tests\Unit\Service;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Query;
use Doctrine\ORM\QueryBuilder;
use InvalidArgumentException;
use Nowo\UptimeMonitorBundle\Entity\Monitor;
use Nowo\UptimeMonitorBundle\Entity\Tenant;
use Nowo\UptimeMonitorBundle\Enum\CheckStatus;
use Nowo\UptimeMonitorBundle\Enum\MonitorType;
use Nowo\UptimeMonitorBundle\Repository\MonitorRepository;
use Nowo\UptimeMonitorBundle\Repository\TenantRepository;
use Nowo\UptimeMonitorBundle\Service\UptimeDataClearService;
use PHPUnit\Framework\TestCase;

/**
 * @covers \Nowo\UptimeMonitorBundle\Service\UptimeDataClearService
 */
final class UptimeDataClearServiceTest extends TestCase
{
    public function testClearDeletesAndResetsMonitors(): void
    {
        $tenant  = new Tenant('main', 'Main');
        $monitor = new Monitor($tenant, 'API', MonitorType::Https, 'https://example.test');
        $monitor->setLastKnownStatus(CheckStatus::Up);

        $query = $this->createMock(Query::class);
        $query->method('execute')->willReturn(5);

        $qb = $this->createMock(QueryBuilder::class);
        $qb->method('delete')->willReturnSelf();
        $qb->method('andWhere')->willReturnSelf();
        $qb->method('setParameter')->willReturnSelf();
        $qb->method('getQuery')->willReturn($query);

        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('createQueryBuilder')->willReturn($qb);
        $em->expects(self::once())->method('flush');

        $tenantRepo  = $this->createMock(TenantRepository::class);
        $monitorRepo = $this->createMock(MonitorRepository::class);
        $monitorRepo->method('findAll')->willReturn([$monitor]);

        $service = new UptimeDataClearService($em, $tenantRepo, $monitorRepo);
        $counts  = $service->clear();

        self::assertSame(5, $counts['checks']);
        self::assertSame(5, $counts['aggregates']);
        self::assertSame(5, $counts['incidents']);
        self::assertSame(1, $counts['monitors_reset']);
        self::assertNull($monitor->getLastKnownStatus());
        self::assertNotNull($monitor->getNextCheckAt());
    }

    public function testClearThrowsWhenTenantMissing(): void
    {
        $tenantRepo = $this->createMock(TenantRepository::class);
        $tenantRepo->method('findOneBySlug')->with('missing')->willReturn(null);

        $service = new UptimeDataClearService(
            $this->createMock(EntityManagerInterface::class),
            $tenantRepo,
            $this->createMock(MonitorRepository::class),
        );

        $this->expectException(InvalidArgumentException::class);
        $service->clear('missing');
    }
}
