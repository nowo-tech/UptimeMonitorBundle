<?php

declare(strict_types=1);

namespace Nowo\UptimeMonitorBundle\Tests\Unit\Controller\Api;

use Nowo\UptimeMonitorBundle\Controller\Api\AggregatesApiController;
use Nowo\UptimeMonitorBundle\Entity\Monitor;
use Nowo\UptimeMonitorBundle\Entity\Tenant;
use Nowo\UptimeMonitorBundle\Enum\MonitorType;
use Nowo\UptimeMonitorBundle\Repository\CheckAggregateRepository;
use Nowo\UptimeMonitorBundle\Repository\MonitorRepository;
use Nowo\UptimeMonitorBundle\Repository\TenantRepository;
use Nowo\UptimeMonitorBundle\Service\AggregateChartService;
use Nowo\UptimeMonitorBundle\Tests\Unit\Support\ControllerContainerTrait;
use Nowo\UptimeMonitorBundle\Tests\Unit\Support\EntityIdTrait;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * @covers \Nowo\UptimeMonitorBundle\Controller\Api\AggregatesApiController
 */
final class AggregatesApiControllerTest extends TestCase
{
    use ControllerContainerTrait;
    use EntityIdTrait;

    private function chartService(): AggregateChartService
    {
        $repo = $this->getMockBuilder(CheckAggregateRepository::class)
            ->onlyMethods(['findForMonitorInRange', 'findTenantOverview'])
            ->disableOriginalConstructor()
            ->getMock();
        $repo->method('findForMonitorInRange')->willReturn([]);
        $repo->method('findTenantOverview')->willReturn([]);

        return new AggregateChartService($repo);
    }

    public function testMonitorAggregates(): void
    {
        $tenant  = new Tenant('main', 'Main');
        $monitor = new Monitor($tenant, 'API', MonitorType::Https, 'https://x.test');
        $this->setEntityId($monitor, 9);

        $monitorRepo = $this->createMock(MonitorRepository::class);
        $monitorRepo->method('find')->willReturn($monitor);

        $controller = new AggregatesApiController(
            $this->createMock(TenantRepository::class),
            $monitorRepo,
            $this->chartService(),
        );
        $this->bindController($controller);

        $response = $controller->monitorAggregates('main', 9, new Request(['period' => 'hour', 'days' => '400']));
        $data     = json_decode((string) $response->getContent(), true);

        self::assertSame(9, $data['monitor_id']);
        self::assertSame('hour', $data['period']);
        self::assertSame(365, $data['days']);
    }

    public function testMonitorAggregatesNotFound(): void
    {
        $monitorRepo = $this->createMock(MonitorRepository::class);
        $monitorRepo->method('find')->willReturn(null);

        $controller = new AggregatesApiController(
            $this->createMock(TenantRepository::class),
            $monitorRepo,
            $this->chartService(),
        );
        $this->bindController($controller);

        $response = $controller->monitorAggregates('main', 1, new Request());
        self::assertSame(Response::HTTP_NOT_FOUND, $response->getStatusCode());
    }

    public function testTenantOverview(): void
    {
        $tenantRepo = $this->createMock(TenantRepository::class);
        $tenantRepo->method('findOneBySlug')->willReturn(new Tenant('main', 'Main'));

        $controller = new AggregatesApiController($tenantRepo, $this->createMock(MonitorRepository::class), $this->chartService());
        $this->bindController($controller);

        $response = $controller->tenantOverview('main', new Request(['days' => '0']));
        $data     = json_decode((string) $response->getContent(), true);

        self::assertSame('main', $data['tenant']);
        self::assertSame(1, $data['days']);
    }

    public function testTenantOverviewNotFound(): void
    {
        $tenantRepo = $this->createMock(TenantRepository::class);
        $tenantRepo->method('findOneBySlug')->willReturn(null);

        $controller = new AggregatesApiController(
            $tenantRepo,
            $this->createMock(MonitorRepository::class),
            $this->chartService(),
        );
        $this->bindController($controller);

        $response = $controller->tenantOverview('missing', new Request());
        self::assertSame(Response::HTTP_NOT_FOUND, $response->getStatusCode());
    }
}
