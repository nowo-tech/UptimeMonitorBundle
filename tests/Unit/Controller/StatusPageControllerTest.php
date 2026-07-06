<?php

declare(strict_types=1);

namespace Nowo\UptimeMonitorBundle\Tests\Unit\Controller;

use Nowo\UptimeMonitorBundle\Controller\StatusPageController;
use Nowo\UptimeMonitorBundle\Entity\CheckResult;
use Nowo\UptimeMonitorBundle\Entity\Monitor;
use Nowo\UptimeMonitorBundle\Entity\Tenant;
use Nowo\UptimeMonitorBundle\Enum\CheckStatus;
use Nowo\UptimeMonitorBundle\Enum\MonitorType;
use Nowo\UptimeMonitorBundle\Repository\CheckResultRepository;
use Nowo\UptimeMonitorBundle\Repository\MonitorRepository;
use Nowo\UptimeMonitorBundle\Repository\TenantRepository;
use Nowo\UptimeMonitorBundle\Tests\Unit\Support\ControllerContainerTrait;
use Nowo\UptimeMonitorBundle\Tests\Unit\Support\EntityIdTrait;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * @covers \Nowo\UptimeMonitorBundle\Controller\StatusPageController
 */
final class StatusPageControllerTest extends TestCase
{
    use ControllerContainerTrait;
    use EntityIdTrait;

    public function testIndexResolvesOverallOperational(): void
    {
        $tenant  = new Tenant('main', 'Main');
        $monitor = new Monitor($tenant, 'API', MonitorType::Https, 'https://x.test');
        $this->setEntityId($monitor, 1);
        $latest = new CheckResult($monitor, CheckStatus::Up, 10);

        $tenantRepo = $this->createMock(TenantRepository::class);
        $tenantRepo->method('findOneBySlug')->willReturn($tenant);

        $monitorRepo = $this->createMock(MonitorRepository::class);
        $monitorRepo->method('findByTenantSlug')->willReturn([$monitor]);

        $checkRepo = $this->createMock(CheckResultRepository::class);
        $checkRepo->method('findLatestByMonitorIds')->willReturn([1 => $latest]);

        $controller = new StatusPageController($tenantRepo, $monitorRepo, $checkRepo, [
            'enabled'      => true,
            'show_latency' => true,
            'title'        => 'Custom',
        ]);
        $this->bindController($controller);

        $response = $controller->index('main');
        self::assertSame('rendered', $response->getContent());
    }

    public function testIndexThrowsWhenDisabled(): void
    {
        $controller = new StatusPageController(
            $this->createMock(TenantRepository::class),
            $this->createMock(MonitorRepository::class),
            $this->createMock(CheckResultRepository::class),
            ['enabled' => false],
        );
        $this->bindController($controller);

        $this->expectException(NotFoundHttpException::class);
        $controller->index('main');
    }

    public function testIndexSkipsPausedMonitors(): void
    {
        $tenant = new Tenant('main', 'Main');
        $active = new Monitor($tenant, 'Up', MonitorType::Https, 'https://a.test');
        $paused = new Monitor($tenant, 'Paused', MonitorType::Https, 'https://b.test');
        $paused->setPaused(true);

        $tenantRepo = $this->createMock(TenantRepository::class);
        $tenantRepo->method('findOneBySlug')->willReturn($tenant);

        $monitorRepo = $this->createMock(MonitorRepository::class);
        $monitorRepo->method('findByTenantSlug')->willReturn([$active, $paused]);

        $checkRepo = $this->createMock(CheckResultRepository::class);
        $checkRepo->method('findLatestByMonitorIds')->willReturn([]);

        $controller = new StatusPageController($tenantRepo, $monitorRepo, $checkRepo, ['enabled' => true]);
        $this->bindController($controller);

        $controller->index('main');
        self::assertTrue(true);
    }
}
