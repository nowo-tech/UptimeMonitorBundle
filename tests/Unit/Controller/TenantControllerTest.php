<?php

declare(strict_types=1);

namespace Nowo\UptimeMonitorBundle\Tests\Unit\Controller;

use Doctrine\ORM\EntityManagerInterface;
use Nowo\UptimeMonitorBundle\Controller\TenantController;
use Nowo\UptimeMonitorBundle\Entity\Tenant;
use Nowo\UptimeMonitorBundle\Repository\TenantRepository;
use Nowo\UptimeMonitorBundle\Tests\Unit\Support\ControllerContainerTrait;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;

/**
 * @covers \Nowo\UptimeMonitorBundle\Controller\TenantController
 */
final class TenantControllerTest extends TestCase
{
    use ControllerContainerTrait;

    public function testIndexListsTenants(): void
    {
        $tenantRepo = $this->createMock(TenantRepository::class);
        $tenantRepo->method('findBy')->willReturn([new Tenant('main', 'Main')]);

        $controller = $this->createController([], $tenantRepo);
        $this->bindController($controller);

        self::assertSame('rendered', $controller->index()->getContent());
    }

    public function testIndexRedirectsWhenTenantListDisabled(): void
    {
        $tenantRepo = $this->createMock(TenantRepository::class);
        $tenantRepo->method('findBy')->willReturn([new Tenant('main', 'Main')]);

        $controller = $this->createController(['list_enabled' => false], $tenantRepo);
        $this->bindController($controller);

        $response = $controller->index();

        self::assertTrue($response->isRedirect('/generated/nowo_uptime_dashboard'));
    }

    public function testIndexRedirectsWhenSingleTenantAndRedirectWhenSingleEnabled(): void
    {
        $tenantRepo = $this->createMock(TenantRepository::class);
        $tenantRepo->method('findBy')->willReturn([new Tenant('acme', 'Acme')]);

        $controller = $this->createController(['redirect_when_single' => true], $tenantRepo);
        $this->bindController($controller);

        self::assertTrue($controller->index()->isRedirect('/generated/nowo_uptime_dashboard'));
    }

    public function testNewRendersFormOnGet(): void
    {
        $request    = Request::create('/tenants/new', 'GET');
        $controller = $this->createController();
        $this->bindController($controller, true, $request);

        self::assertSame('rendered', $controller->new($request)->getContent());
    }

    /**
     * @param array<string, mixed> $tenantsConfig
     */
    private function createController(array $tenantsConfig = [], ?TenantRepository $tenantRepository = null): TenantController
    {
        return new TenantController(
            $tenantRepository ?? $this->createMock(TenantRepository::class),
            $this->createMock(EntityManagerInterface::class),
            ['list_enabled'   => true, 'redirect_when_single' => false, ...$tenantsConfig],
            ['default_tenant' => 'main'],
        );
    }

    public function testNewPersistsTenantOnValidPost(): void
    {
        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects(self::once())->method('persist');
        $em->expects(self::once())->method('flush');

        $request = Request::create('/tenants/new', 'POST', [
            'tenant_form' => [
                'slug' => 'acme',
                'name' => 'Acme',
            ],
        ]);
        $request->headers->set('Content-Type', 'application/x-www-form-urlencoded');

        $tenantRepo = $this->createMock(TenantRepository::class);
        $controller = new TenantController(
            $tenantRepo,
            $em,
            ['list_enabled'   => true, 'redirect_when_single' => false],
            ['default_tenant' => 'main'],
        );
        $this->bindController($controller, true, $request);

        self::assertTrue($controller->new($request)->isRedirect());
    }
}
