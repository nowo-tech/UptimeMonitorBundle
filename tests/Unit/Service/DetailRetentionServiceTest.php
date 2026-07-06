<?php

declare(strict_types=1);

namespace Nowo\UptimeMonitorBundle\Tests\Unit\Service;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Query;
use Doctrine\ORM\QueryBuilder;
use Nowo\UptimeMonitorBundle\Entity\Tenant;
use Nowo\UptimeMonitorBundle\Repository\TenantRepository;
use Nowo\UptimeMonitorBundle\Service\DetailRetentionService;
use PHPUnit\Framework\TestCase;

/**
 * @covers \Nowo\UptimeMonitorBundle\Service\DetailRetentionService
 */
final class DetailRetentionServiceTest extends TestCase
{
    public function testPurgeReturnsZeroWhenDisabled(): void
    {
        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects(self::never())->method('createQueryBuilder');

        $tenantRepo = $this->createMock(TenantRepository::class);
        $tenantRepo->method('findAll')->willReturn([new Tenant('main', 'Main')]);

        $service = new DetailRetentionService($em, $tenantRepo, ['purge_enabled' => false, 'detail_days' => 30]);

        self::assertSame(0, $service->purgeExpiredDetail());
    }

    public function testPurgeReturnsZeroWhenDetailDaysInvalid(): void
    {
        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects(self::never())->method('createQueryBuilder');

        $tenantRepo = $this->createMock(TenantRepository::class);
        $tenantRepo->method('findAll')->willReturn([new Tenant('main', 'Main')]);

        $service = new DetailRetentionService($em, $tenantRepo, ['purge_enabled' => true, 'detail_days' => null]);

        self::assertSame(0, $service->purgeExpiredDetail());
    }

    public function testPurgeDeletesExpiredRows(): void
    {
        $query = $this->createMock(Query::class);
        $query->method('execute')->willReturn(5);

        $qb = $this->createMock(QueryBuilder::class);
        $qb->method('delete')->willReturnSelf();
        $qb->method('andWhere')->willReturnSelf();
        $qb->method('setParameter')->willReturnSelf();
        $qb->method('getQuery')->willReturn($query);

        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('createQueryBuilder')->willReturn($qb);

        $tenantRepo = $this->createMock(TenantRepository::class);
        $tenantRepo->method('findAll')->willReturn([new Tenant('main', 'Main')]);

        $service = new DetailRetentionService($em, $tenantRepo, ['purge_enabled' => true, 'detail_days' => 7]);

        self::assertSame(5, $service->purgeExpiredDetail());
    }
}
