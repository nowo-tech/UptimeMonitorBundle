<?php

declare(strict_types=1);

namespace Nowo\UptimeMonitorBundle\Tests\Unit\Controller;

use Doctrine\ORM\EntityManagerInterface;
use Nowo\UptimeMonitorBundle\Controller\TenantController;
use Nowo\UptimeMonitorBundle\Entity\Tenant;
use Nowo\UptimeMonitorBundle\Repository\TenantRepository;
use Nowo\UptimeMonitorBundle\Security\TenantAwareUptimeMonitorAccessCheckerInterface;
use Nowo\UptimeMonitorBundle\Security\UptimeMonitorAccessCheckerInterface;
use Nowo\UptimeMonitorBundle\Tests\Unit\Support\ControllerContainerTrait;
use Nowo\UptimeMonitorBundle\Ui\UptimePages;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use stdClass;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorage;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Symfony\Component\Security\Core\User\InMemoryUser;

#[CoversClass(TenantController::class)]
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
    private function createController(
        array $tenantsConfig = [],
        ?TenantRepository $tenantRepository = null,
        ?UptimeMonitorAccessCheckerInterface $checker = null,
        ?TokenStorage $tokens = null,
    ): TenantController {
        return new TenantController(
            $tenantRepository ?? $this->createMock(TenantRepository::class),
            $this->createMock(EntityManagerInterface::class),
            ['list_enabled' => true, 'redirect_when_single' => false, ...$tenantsConfig],
            ['default_tenant' => 'main'],
            new UptimePages(),
            $checker,
            $tokens,
        );
    }

    public function testNewPersistsTenantOnValidPost(): void
    {
        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects(self::once())->method('persist');
        $em->expects(self::once())->method('flush');

        $request = Request::create('/tenants/new', 'POST', [
            'tenant_form' => [
                'slug'   => 'acme',
                'name'   => 'Acme',
                '_token' => 'valid',
            ],
        ]);
        $request->headers->set('Content-Type', 'application/x-www-form-urlencoded');

        $tenantRepo = $this->createMock(TenantRepository::class);
        $controller = new TenantController(
            $tenantRepo,
            $em,
            ['list_enabled' => true, 'redirect_when_single' => false],
            ['default_tenant' => 'main'],
        );
        $this->bindController($controller, true, $request);

        self::assertTrue($controller->new($request)->isRedirect());
    }

    public function testListDisabledFallsBackToTheFirstTenantWhenTheDefaultIsMissing(): void
    {
        $tenantRepo = $this->createStub(TenantRepository::class);
        $tenantRepo->method('findBy')->willReturn([new Tenant('acme', 'Acme'), new Tenant('beta', 'Beta')]);

        $controller = $this->createController(['list_enabled' => false], $tenantRepo);
        $this->bindController($controller);

        self::assertTrue($controller->index()->isRedirect('/generated/nowo_uptime_dashboard'));
    }

    public function testTenantAwareCheckerFiltersTheList(): void
    {
        $tenantRepo = $this->createStub(TenantRepository::class);
        $tenantRepo->method('findBy')->willReturn([new Tenant('acme', 'Acme'), new Tenant('beta', 'Beta')]);

        $user    = new InMemoryUser('alice', null);
        $checker = $this->createMock(TenantAwareUptimeMonitorAccessCheckerInterface::class);
        $checker->expects(self::exactly(2))->method('canAccessTenant')->willReturnCallback(
            static fn (object $who, string $slug): bool => $who === $user && $slug === 'beta',
        );
        $tokens = new TokenStorage();
        $tokens->setToken(new UsernamePasswordToken($user, 'main'));

        // Only "beta" is visible: with redirect_when_single the single visible tenant opens directly.
        $controller = $this->createController(['redirect_when_single' => true], $tenantRepo, $checker, $tokens);
        $this->bindController($controller);

        self::assertTrue($controller->index()->isRedirect('/generated/nowo_uptime_dashboard'));
    }

    public function testNoVisibleTenantWithTheListDisabledIsAccessDenied(): void
    {
        $tenantRepo = $this->createStub(TenantRepository::class);
        $tenantRepo->method('findBy')->willReturn([new Tenant('acme', 'Acme')]);

        $checker = $this->createMock(TenantAwareUptimeMonitorAccessCheckerInterface::class);
        $checker->method('canAccessTenant')->willReturnCallback(static fn (object $who): bool => !$who instanceof stdClass);

        $controller = $this->createController(['list_enabled' => false], $tenantRepo, $checker);
        $this->bindController($controller);

        $this->expectException(AccessDeniedException::class);
        $controller->index();
    }

    public function testGlobalCheckerKeepsEveryTenant(): void
    {
        $tenantRepo = $this->createStub(TenantRepository::class);
        $tenantRepo->method('findBy')->willReturn([new Tenant('acme', 'Acme'), new Tenant('beta', 'Beta')]);

        $controller = $this->createController(['redirect_when_single' => true], $tenantRepo, $this->createStub(UptimeMonitorAccessCheckerInterface::class));
        $this->bindController($controller);

        self::assertSame('rendered', $controller->index()->getContent());
    }
}
