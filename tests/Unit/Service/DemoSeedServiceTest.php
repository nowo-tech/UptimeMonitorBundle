<?php

declare(strict_types=1);

namespace Nowo\UptimeMonitorBundle\Tests\Unit\Service;

use Doctrine\ORM\EntityManagerInterface;
use Nowo\UptimeMonitorBundle\Entity\Tenant;
use Nowo\UptimeMonitorBundle\Repository\MonitorRepository;
use Nowo\UptimeMonitorBundle\Repository\TenantRepository;
use Nowo\UptimeMonitorBundle\Service\DemoSeedService;
use PHPUnit\Framework\TestCase;
use stdClass;

/**
 * @covers \Nowo\UptimeMonitorBundle\Service\DemoSeedService
 */
final class DemoSeedServiceTest extends TestCase
{
    public function testSeedCreatesTenantAndMonitors(): void
    {
        $tenantRepository = $this->createMock(TenantRepository::class);
        $tenantRepository->method('findOneBySlug')->willReturn(null);

        $monitorRepository = $this->createMock(MonitorRepository::class);
        $monitorRepository->method('findOneBy')->willReturn(null);

        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects(self::atLeastOnce())->method('persist');
        $entityManager->expects(self::atLeastOnce())->method('flush');

        $service = new DemoSeedService($entityManager, $tenantRepository, $monitorRepository);
        $result  = $service->seed('main', 'Main');

        self::assertInstanceOf(Tenant::class, $result['tenant']);
        self::assertSame('main', $result['tenant']->getSlug());
        self::assertSame(7, $result['monitors_created']);
    }

    public function testFreshSeedRemovesMonitorsBeforeCreate(): void
    {
        $tenant = new Tenant('main', 'Main');

        $tenantRepository = $this->createMock(TenantRepository::class);
        $tenantRepository->method('findOneBySlug')->willReturn($tenant);

        $child             = new \Nowo\UptimeMonitorBundle\Entity\Monitor($tenant, 'Old', MonitorType::Https, 'https://example.test');
        $monitorRepository = $this->createMock(MonitorRepository::class);
        $monitorRepository->method('findByTenantSlug')->willReturn([$child]);
        $monitorRepository->method('findOneBy')->willReturn(null);

        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects(self::atLeastOnce())->method('remove');
        $entityManager->expects(self::atLeastOnce())->method('persist');
        $entityManager->expects(self::atLeastOnce())->method('flush');

        $service = new DemoSeedService($entityManager, $tenantRepository, $monitorRepository);
        $result  = $service->freshSeed();

        self::assertSame(1, $result['monitors_removed']);
        self::assertSame(7, $result['monitors_created']);
    }

    public function testSeedSkipsExistingMonitors(): void
    {
        $tenant = new Tenant('main', 'Main');

        $tenantRepository = $this->createMock(TenantRepository::class);
        $tenantRepository->method('findOneBySlug')->willReturn($tenant);

        $monitorRepository = $this->createMock(MonitorRepository::class);
        $monitorRepository->method('findOneBy')->willReturn(new stdClass());

        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects(self::never())->method('persist');

        $service = new DemoSeedService($entityManager, $tenantRepository, $monitorRepository);
        $result  = $service->seed();

        self::assertSame(0, $result['monitors_created']);
    }
}
