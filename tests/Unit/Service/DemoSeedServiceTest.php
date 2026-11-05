<?php

declare(strict_types=1);

namespace Nowo\UptimeMonitorBundle\Tests\Unit\Service;

use Doctrine\ORM\EntityManagerInterface;
use Nowo\UptimeMonitorBundle\Entity\Monitor;
use Nowo\UptimeMonitorBundle\Entity\Tenant;
use Nowo\UptimeMonitorBundle\Enum\MonitorType;
use Nowo\UptimeMonitorBundle\Repository\MonitorRepository;
use Nowo\UptimeMonitorBundle\Repository\TenantRepository;
use Nowo\UptimeMonitorBundle\Service\DemoSeedService;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(DemoSeedService::class)]
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

        self::assertSame('main', $result['tenant']->getSlug());
        self::assertSame(3, $result['monitors_created']);
    }

    public function testFreshSeedRemovesMonitorsBeforeCreate(): void
    {
        $tenant = new Tenant('main', 'Main');

        $tenantRepository = $this->createMock(TenantRepository::class);
        $tenantRepository->method('findOneBySlug')->willReturn($tenant);

        $child             = new Monitor($tenant, 'Old', MonitorType::Https, 'https://example.test');
        $fromFile          = (new Monitor($tenant, 'Agent', MonitorType::Push, ''))->markFileManaged('vps-1.resources');
        $monitorRepository = $this->createMock(MonitorRepository::class);
        $monitorRepository->method('findByTenantSlug')->willReturn([$child, $fromFile]);
        $monitorRepository->method('findOneBy')->willReturn(null);

        $entityManager = $this->createMock(EntityManagerInterface::class);
        // A file-managed monitor survives the demo reset (audit FI-I8).
        $entityManager->expects(self::once())->method('remove')->with($child);
        $entityManager->expects(self::atLeastOnce())->method('persist');
        $entityManager->expects(self::atLeastOnce())->method('flush');

        $service = new DemoSeedService($entityManager, $tenantRepository, $monitorRepository);
        $result  = $service->freshSeed();

        self::assertSame(1, $result['monitors_removed']);
        self::assertSame(3, $result['monitors_created']);
    }

    public function testSeedSkipsExistingMonitors(): void
    {
        $tenant = new Tenant('main', 'Main');

        $tenantRepository = $this->createMock(TenantRepository::class);
        $tenantRepository->method('findOneBySlug')->willReturn($tenant);

        $monitorRepository = $this->createMock(MonitorRepository::class);
        $monitorRepository->method('findOneBy')->willReturnCallback(
            static fn (array $criteria): Monitor => new Monitor(
                $tenant,
                (string) ($criteria['name'] ?? 'existing'),
                $criteria['type'] ?? MonitorType::Http,
                'target',
            ),
        );

        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects(self::never())->method('persist');

        $service = new DemoSeedService($entityManager, $tenantRepository, $monitorRepository);
        $result  = $service->seed();

        self::assertSame(0, $result['monitors_created']);
    }
}
