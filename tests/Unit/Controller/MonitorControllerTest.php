<?php

declare(strict_types=1);

namespace Nowo\UptimeMonitorBundle\Tests\Unit\Controller;

use Doctrine\ORM\EntityManagerInterface;
use Nowo\UptimeMonitorBundle\Controller\MonitorController;
use Nowo\UptimeMonitorBundle\Entity\Monitor;
use Nowo\UptimeMonitorBundle\Entity\Tenant;
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
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * @covers \Nowo\UptimeMonitorBundle\Controller\MonitorController
 */
final class MonitorControllerTest extends TestCase
{
    use ControllerContainerTrait;
    use EntityIdTrait;
    use SyncDispatcherTestTrait;

    private function chartService(): AggregateChartService
    {
        $repo = $this->getMockBuilder(CheckAggregateRepository::class)
            ->onlyMethods(['findForMonitorInRange'])
            ->disableOriginalConstructor()
            ->getMock();
        $repo->method('findForMonitorInRange')->willReturn([]);

        return new AggregateChartService($repo);
    }

    private function createController(
        ?Tenant $tenant = null,
        ?Monitor $monitor = null,
        ?EntityManagerInterface $em = null,
    ): MonitorController {
        $tenant ??= new Tenant('main', 'Main');
        $monitor ??= new Monitor($tenant, 'API', MonitorType::Https, 'https://x.test');
        $this->setEntityId($monitor, 4);

        $tenantRepo = $this->createMock(TenantRepository::class);
        $tenantRepo->method('findOneBySlug')->willReturn($tenant);

        $monitorRepo = $this->createMock(MonitorRepository::class);
        $monitorRepo->method('find')->willReturn($monitor);

        return new MonitorController(
            $this->createMock(TranslatorInterface::class),
            $tenantRepo,
            $monitorRepo,
            $this->createMock(CheckResultRepository::class),
            $this->monitorFactory($monitorRepo),
            $this->chartService(),
            $this->dashboardViewBuilder($monitorRepo),
            $em ?? $this->createMock(EntityManagerInterface::class),
        );
    }

    public function testShowRendersMonitor(): void
    {
        $controller = $this->createController();
        $this->bindController($controller);

        self::assertSame('rendered', $controller->show('main', 4)->getContent());
    }

    public function testNewRendersFormOnGet(): void
    {
        $request    = Request::create('/main/monitors/new', 'GET');
        $controller = $this->createController();
        $this->bindController($controller, true, $request);

        self::assertSame('rendered', $controller->new('main', $request)->getContent());
    }

    public function testNewRendersModalPartialOnGetWithHeader(): void
    {
        $request = Request::create('/main/monitors/new', 'GET');
        $request->headers->set('X-Uptime-Modal', '1');
        $controller = $this->createController();
        $this->bindController($controller, true, $request);

        $response = $controller->new('main', $request);
        self::assertSame(200, $response->getStatusCode());
        self::assertSame('rendered', $response->getContent());
    }

    public function testDeleteRemovesMonitorWithValidCsrf(): void
    {
        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects(self::once())->method('remove');
        $em->expects(self::once())->method('flush');

        $request = Request::create('/main/monitors/4/delete', 'POST', ['_token' => 'valid']);

        $controller = $this->createController(em: $em);
        $this->bindController($controller, true, $request);

        self::assertTrue($controller->delete('main', 4, $request)->isRedirect());
    }

    public function testTogglePauseFlipsPausedFlag(): void
    {
        $monitor = new Monitor(new Tenant('main', 'Main'), 'API', MonitorType::Https, 'https://x.test');
        $this->setEntityId($monitor, 4);

        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects(self::once())->method('flush');

        $request = Request::create('/main/monitors/4/toggle-pause', 'POST', ['_token' => 'valid']);

        $controller = $this->createController(monitor: $monitor, em: $em);
        $this->bindController($controller, true, $request);
        $controller->togglePause('main', 4, $request);

        self::assertTrue($monitor->isPaused());
    }

    public function testRequireMonitorThrowsForWrongTenant(): void
    {
        $tenant  = new Tenant('other', 'Other');
        $monitor = new Monitor($tenant, 'API', MonitorType::Https, 'https://x.test');
        $this->setEntityId($monitor, 4);

        $controller = $this->createController(monitor: $monitor);
        $this->bindController($controller);

        $this->expectException(NotFoundHttpException::class);
        $controller->show('main', 4);
    }

    public function testEditRendersFormOnGet(): void
    {
        $request    = Request::create('/main/monitors/4/edit', 'GET');
        $controller = $this->createController();
        $this->bindController($controller, true, $request);

        self::assertSame('rendered', $controller->edit('main', 4, $request)->getContent());
    }

    public function testEditRendersModalPartialOnGetWithHeader(): void
    {
        $request = Request::create('/main/monitors/4/edit', 'GET');
        $request->headers->set('X-Uptime-Modal', '1');
        $controller = $this->createController();
        $this->bindController($controller, true, $request);

        $response = $controller->edit('main', 4, $request);
        self::assertSame(200, $response->getStatusCode());
        self::assertSame('rendered', $response->getContent());
    }

    public function testDeleteSkipsWhenCsrfInvalid(): void
    {
        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects(self::never())->method('remove');

        $request    = Request::create('/main/monitors/4/delete', 'POST', ['_token' => 'bad']);
        $controller = $this->createController(em: $em);
        $this->bindController($controller, false, $request);

        self::assertTrue($controller->delete('main', 4, $request)->isRedirect());
    }

    public function testTogglePauseSkipsWhenCsrfInvalid(): void
    {
        $monitor = new Monitor(new Tenant('main', 'Main'), 'API', MonitorType::Https, 'https://x.test');
        $this->setEntityId($monitor, 4);

        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects(self::never())->method('flush');

        $request    = Request::create('/main/monitors/4/toggle-pause', 'POST', ['_token' => 'bad']);
        $controller = $this->createController(monitor: $monitor, em: $em);
        $this->bindController($controller, false, $request);
        $controller->togglePause('main', 4, $request);

        self::assertFalse($monitor->isPaused());
    }
}
