<?php

declare(strict_types=1);

namespace Nowo\UptimeMonitorBundle\Tests\Unit\EventSubscriber;

use Nowo\UptimeMonitorBundle\Controller\Api\AggregatesApiController;
use Nowo\UptimeMonitorBundle\Controller\Api\HistoryApiController;
use Nowo\UptimeMonitorBundle\Controller\Api\StatusApiController;
use Nowo\UptimeMonitorBundle\Controller\DashboardController;
use Nowo\UptimeMonitorBundle\Controller\MonitorController;
use Nowo\UptimeMonitorBundle\Controller\SettingsController;
use Nowo\UptimeMonitorBundle\Controller\StatusPageController;
use Nowo\UptimeMonitorBundle\Controller\SummaryController;
use Nowo\UptimeMonitorBundle\Entity\Tenant;
use Nowo\UptimeMonitorBundle\EventSubscriber\TenantProvisioningSubscriber;
use Nowo\UptimeMonitorBundle\Repository\TenantRepository;
use Nowo\UptimeMonitorBundle\Tenant\MissingTenantProvisionerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\ControllerEvent;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\HttpKernel\KernelEvents;

#[CoversClass(TenantProvisioningSubscriber::class)]
final class TenantProvisioningSubscriberTest extends TestCase
{
    /** A callable action of each controller. */
    private const METHODS = [
        DashboardController::class     => 'index',
        MonitorController::class       => 'show',
        SettingsController::class      => 'general',
        SummaryController::class       => 'index',
        StatusApiController::class     => 'summary',
        HistoryApiController::class    => 'monitorHistory',
        AggregatesApiController::class => 'monitorAggregates',
        StatusPageController::class    => 'index',
    ];

    public function testRunsAfterTheAccessCheck(): void
    {
        self::assertSame([KernelEvents::CONTROLLER => ['onKernelController', -8]], TenantProvisioningSubscriber::getSubscribedEvents());
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function tenantControllers(): iterable
    {
        foreach (self::METHODS as $class => $method) {
            if ($class !== StatusPageController::class) {
                yield $class => [$class];
            }
        }
    }

    /**
     * @param class-string $class
     */
    #[DataProvider('tenantControllers')]
    public function testAMissingTenantIsProvisioned(string $class): void
    {
        $provisioner = $this->createMock(MissingTenantProvisionerInterface::class);
        $provisioner->expects(self::once())->method('provision')->with('acme')->willReturn(new Tenant('acme', 'Acme'));

        (new TenantProvisioningSubscriber($this->tenants(null), $provisioner))->onKernelController($this->event($class, 'acme'));
    }

    public function testAnExistingTenantIsLeftAlone(): void
    {
        $provisioner = $this->createMock(MissingTenantProvisionerInterface::class);
        $provisioner->expects(self::never())->method('provision');

        (new TenantProvisioningSubscriber($this->tenants(new Tenant('acme', 'Acme')), $provisioner))->onKernelController($this->event(DashboardController::class, 'acme'));
    }

    public function testOtherRequestsNeverProvision(): void
    {
        $provisioner = $this->createMock(MissingTenantProvisionerInterface::class);
        $provisioner->expects(self::never())->method('provision');
        $subscriber = new TenantProvisioningSubscriber($this->tenants(null), $provisioner);

        $subscriber->onKernelController($this->event(StatusPageController::class, 'acme'));
        $subscriber->onKernelController($this->event(DashboardController::class, null));
        $subscriber->onKernelController($this->event(DashboardController::class, ''));
        $subscriber->onKernelController($this->event(DashboardController::class, 'acme', HttpKernelInterface::SUB_REQUEST));
        $subscriber->onKernelController(new ControllerEvent($this->createStub(HttpKernelInterface::class), static fn (): null => null, new Request(), HttpKernelInterface::MAIN_REQUEST));
    }

    public function testWithoutProvisionerNothingHappens(): void
    {
        $tenants = $this->createMock(TenantRepository::class);
        $tenants->expects(self::never())->method('findOneBySlug');

        (new TenantProvisioningSubscriber($tenants))->onKernelController($this->event(DashboardController::class, 'acme'));
    }

    public function testNoTenantIsNotFound(): void
    {
        $provisioner = $this->createStub(MissingTenantProvisionerInterface::class);
        $provisioner->method('provision')->willReturn(null);

        $this->expectException(NotFoundHttpException::class);
        (new TenantProvisioningSubscriber($this->tenants(null), $provisioner))->onKernelController($this->event(DashboardController::class, 'acme'));
    }

    public function testATenantOfAnotherSlugIsNotFound(): void
    {
        $provisioner = $this->createStub(MissingTenantProvisionerInterface::class);
        $provisioner->method('provision')->willReturn(new Tenant('other', 'Other'));

        $this->expectException(NotFoundHttpException::class);
        (new TenantProvisioningSubscriber($this->tenants(null), $provisioner))->onKernelController($this->event(DashboardController::class, 'acme'));
    }

    private function tenants(?Tenant $tenant): TenantRepository
    {
        $tenants = $this->createStub(TenantRepository::class);
        $tenants->method('findOneBySlug')->willReturn($tenant);

        return $tenants;
    }

    /**
     * @param class-string $class
     */
    private function event(string $class, ?string $slug, int $type = HttpKernelInterface::MAIN_REQUEST): ControllerEvent
    {
        $request = new Request();
        if ($slug !== null) {
            $request->attributes->set('tenantSlug', $slug);
        }
        $controller = [(new ReflectionClass($class))->newInstanceWithoutConstructor(), self::METHODS[$class]];

        // Symfony accepts [controller, method]; PHPStan types ControllerEvent as callable(): mixed only.
        return new ControllerEvent($this->createStub(HttpKernelInterface::class), $controller, $request, $type); // @phpstan-ignore argument.type
    }
}
