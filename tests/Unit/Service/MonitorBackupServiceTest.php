<?php

declare(strict_types=1);

namespace Nowo\UptimeMonitorBundle\Tests\Unit\Service;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use Doctrine\Persistence\ObjectManager;
use Nowo\UptimeMonitorBundle\Entity\Tenant;
use Nowo\UptimeMonitorBundle\Repository\MonitorRepository;
use Nowo\UptimeMonitorBundle\Service\MonitorBackupService;
use Nowo\UptimeMonitorBundle\Service\MonitorFactory;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * @covers \Nowo\UptimeMonitorBundle\Service\MonitorBackupService
 */
final class MonitorBackupServiceTest extends TestCase
{
    public function testFailedFlushResetsClosedManagerAndRethrows(): void
    {
        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('flush')->willThrowException(new RuntimeException('constraint violation'));
        $em->method('isOpen')->willReturn(false);

        $registry = $this->createMock(ManagerRegistry::class);
        $registry->method('getManagers')->willReturn(['default' => $em, 'odm' => $this->createMock(ObjectManager::class)]);
        $registry->expects(self::once())->method('resetManager')->with('default');

        $service = $this->service($em, $registry);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('constraint violation');
        $service->import(new Tenant('main', 'Main'), ['monitors' => []]);
    }

    public function testFailedFlushWithOpenManagerDoesNotReset(): void
    {
        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('flush')->willThrowException(new RuntimeException('boom'));
        $em->method('isOpen')->willReturn(true);

        $registry = $this->createMock(ManagerRegistry::class);
        $registry->expects(self::never())->method('resetManager');

        $this->expectException(RuntimeException::class);
        $this->service($em, $registry)->import(new Tenant('main', 'Main'), ['monitors' => []]);
    }

    public function testFailedFlushWithoutRegistryRethrows(): void
    {
        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('flush')->willThrowException(new RuntimeException('boom'));

        $this->expectException(RuntimeException::class);
        $this->service($em, null)->import(new Tenant('main', 'Main'), ['monitors' => []]);
    }

    private function service(EntityManagerInterface $em, ?ManagerRegistry $registry): MonitorBackupService
    {
        $monitorRepo = $this->createMock(MonitorRepository::class);

        return new MonitorBackupService($monitorRepo, new MonitorFactory($monitorRepo), $em, $registry);
    }
}
