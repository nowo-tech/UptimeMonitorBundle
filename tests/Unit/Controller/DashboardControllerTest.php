<?php

declare(strict_types=1);

namespace Nowo\UptimeMonitorBundle\Tests\Unit\Controller;

use Nowo\UptimeMonitorBundle\Controller\DashboardController;
use Nowo\UptimeMonitorBundle\Entity\CheckResult;
use Nowo\UptimeMonitorBundle\Entity\Monitor;
use Nowo\UptimeMonitorBundle\Entity\Tenant;
use Nowo\UptimeMonitorBundle\Enum\CheckStatus;
use Nowo\UptimeMonitorBundle\Enum\MonitorType;
use Nowo\UptimeMonitorBundle\Repository\CheckAggregateRepository;
use Nowo\UptimeMonitorBundle\Repository\CheckResultRepository;
use Nowo\UptimeMonitorBundle\Repository\MonitorRepository;
use Nowo\UptimeMonitorBundle\Repository\TenantRepository;
use Nowo\UptimeMonitorBundle\Service\AggregateChartService;
use Nowo\UptimeMonitorBundle\Tests\Unit\Support\ControllerContainerTrait;
use Nowo\UptimeMonitorBundle\Tests\Unit\Support\EntityIdTrait;
use Nowo\UptimeMonitorBundle\Tests\Unit\Support\SyncDispatcherTestTrait;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * @covers \Nowo\UptimeMonitorBundle\Controller\DashboardController
 */
final class DashboardControllerTest extends TestCase
{
    use ControllerContainerTrait;
    use EntityIdTrait;
    use SyncDispatcherTestTrait;

    private function chartService(): AggregateChartService
    {
        $repo = $this->getMockBuilder(CheckAggregateRepository::class)
            ->onlyMethods(['findTenantOverview'])
            ->disableOriginalConstructor()
            ->getMock();
        $repo->method('findTenantOverview')->willReturn([]);

        return new AggregateChartService($repo);
    }

    public function testIndexRendersDashboard(): void
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

        $controller = new DashboardController(
            $tenantRepo,
            $monitorRepo,
            $checkRepo,
            $this->chartService(),
            ['poll_interval_ms' => 5000, 'sync' => 'polling'],
            $this->pollingSyncDispatcher(),
        );
        $this->bindController($controller);

        $response = $controller->index('main', new Request());

        self::assertInstanceOf(Response::class, $response);
        self::assertSame('rendered', $response->getContent());
    }

    public function testIndexThrowsWhenTenantMissing(): void
    {
        $tenantRepo = $this->createMock(TenantRepository::class);
        $tenantRepo->method('findOneBySlug')->willReturn(null);

        $controller = new DashboardController(
            $tenantRepo,
            $this->createMock(MonitorRepository::class),
            $this->createMock(CheckResultRepository::class),
            $this->chartService(),
            ['sync' => 'polling'],
            $this->pollingSyncDispatcher(),
        );
        $this->bindController($controller);

        $this->expectException(NotFoundHttpException::class);
        $controller->index('missing', new Request());
    }
}
