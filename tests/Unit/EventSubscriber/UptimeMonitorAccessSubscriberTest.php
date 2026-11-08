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
use Nowo\UptimeMonitorBundle\Controller\TenantController;
use Nowo\UptimeMonitorBundle\Entity\Monitor;
use Nowo\UptimeMonitorBundle\Entity\Tenant;
use Nowo\UptimeMonitorBundle\Enum\MonitorType;
use Nowo\UptimeMonitorBundle\EventSubscriber\UptimeMonitorAccessSubscriber;
use Nowo\UptimeMonitorBundle\Repository\MonitorRepository;
use Nowo\UptimeMonitorBundle\Security\MonitorAwareUptimeMonitorAccessCheckerInterface;
use Nowo\UptimeMonitorBundle\Security\TenantAwareUptimeMonitorAccessCheckerInterface;
use Nowo\UptimeMonitorBundle\Security\UptimeMonitorAccessCheckerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\ControllerEvent;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

#[CoversClass(UptimeMonitorAccessSubscriber::class)]
final class UptimeMonitorAccessSubscriberTest extends TestCase
{
    public function testSkipsWhenAllowUnauthenticated(): void
    {
        $checker = $this->createMock(UptimeMonitorAccessCheckerInterface::class);
        $checker->expects(self::never())->method('canAccessDashboard');

        $subscriber = new UptimeMonitorAccessSubscriber($checker, true);
        $event      = $this->controllerEvent([
            $this->instanceWithoutConstructor(DashboardController::class),
            'index',
        ]);

        $subscriber->onKernelController($event);
    }

    public function testDisabledWebUiAnswersNotFoundForAdminPagesButNotForApis(): void
    {
        $checker = $this->createMock(UptimeMonitorAccessCheckerInterface::class);
        $checker->method('canAccessDashboard')->willReturn(true);
        $subscriber = new UptimeMonitorAccessSubscriber($checker, true, null, false);

        // APIs keep working with the admin UI disabled.
        $subscriber->onKernelController($this->controllerEvent([$this->instanceWithoutConstructor(StatusApiController::class), 'summary']));

        $this->expectException(NotFoundHttpException::class);
        $subscriber->onKernelController($this->controllerEvent([$this->instanceWithoutConstructor(SettingsController::class), 'general']));
    }

    public function testSkipsStatusPageController(): void
    {
        $checker = $this->createMock(UptimeMonitorAccessCheckerInterface::class);
        $checker->expects(self::never())->method(self::anything());

        $subscriber = new UptimeMonitorAccessSubscriber($checker, false);
        $event      = $this->controllerEvent([
            $this->instanceWithoutConstructor(StatusPageController::class),
            'index',
        ]);

        $subscriber->onKernelController($event);
    }

    public function testGetSubscribedEvents(): void
    {
        self::assertSame(
            [KernelEvents::CONTROLLER => ['onKernelController', 0]],
            UptimeMonitorAccessSubscriber::getSubscribedEvents(),
        );
    }

    public function testSkipsInvokableController(): void
    {
        $checker = $this->createMock(UptimeMonitorAccessCheckerInterface::class);
        $checker->expects(self::never())->method(self::anything());

        $subscriber = new UptimeMonitorAccessSubscriber($checker, false);
        $kernel     = $this->createMock(HttpKernelInterface::class);
        $event      = new ControllerEvent($kernel, static fn (): null => null, new Request(), HttpKernelInterface::MAIN_REQUEST);

        $subscriber->onKernelController($event);
    }

    public function testSkipsUnprotectedController(): void
    {
        $checker = $this->createMock(UptimeMonitorAccessCheckerInterface::class);
        $checker->expects(self::never())->method(self::anything());

        $dummy = new class {
            public function index(): void
            {
            }
        };

        $subscriber = new UptimeMonitorAccessSubscriber($checker, false);
        $subscriber->onKernelController($this->controllerEvent([$dummy, 'index']));
    }

    public function testSkipsArrayControllerWithNonObjectFirstElement(): void
    {
        $checker = $this->createMock(UptimeMonitorAccessCheckerInterface::class);
        $checker->expects(self::never())->method(self::anything());

        $subscriber = new UptimeMonitorAccessSubscriber($checker, false);
        $kernel     = $this->createMock(HttpKernelInterface::class);
        $event      = new ControllerEvent(
            $kernel,
            [self::class, 'noopController'],
            new Request(),
            HttpKernelInterface::MAIN_REQUEST,
        );

        $subscriber->onKernelController($event);
    }

    public static function noopController(): void
    {
    }

    public function testDashboardUsesCanAccessDashboard(): void
    {
        $checker = $this->createMock(UptimeMonitorAccessCheckerInterface::class);
        $checker->expects(self::once())->method('canAccessDashboard')->willReturn(true);

        $subscriber = new UptimeMonitorAccessSubscriber($checker, false);
        $subscriber->onKernelController($this->controllerEvent([
            $this->instanceWithoutConstructor(DashboardController::class),
            'index',
        ]));
    }

    public function testMonitorShowUsesDashboardAccess(): void
    {
        $checker = $this->createMock(UptimeMonitorAccessCheckerInterface::class);
        $checker->expects(self::once())->method('canAccessDashboard')->willReturn(true);
        $checker->expects(self::never())->method('canManageMonitors');

        $subscriber = new UptimeMonitorAccessSubscriber($checker, false);
        $subscriber->onKernelController($this->controllerEvent([
            $this->instanceWithoutConstructor(MonitorController::class),
            'show',
        ]));
    }

    public function testMonitorMutationUsesManageAccess(): void
    {
        $checker = $this->createMock(UptimeMonitorAccessCheckerInterface::class);
        $checker->expects(self::once())->method('canManageMonitors')->willReturn(true);

        $subscriber = new UptimeMonitorAccessSubscriber($checker, false);
        $subscriber->onKernelController($this->controllerEvent([
            $this->instanceWithoutConstructor(MonitorController::class),
            'delete',
        ]));
    }

    public function testSettingsUsesCanManageSettings(): void
    {
        $checker = $this->createMock(UptimeMonitorAccessCheckerInterface::class);
        $checker->expects(self::once())->method('canManageSettings')->willReturn(true);

        $subscriber = new UptimeMonitorAccessSubscriber($checker, false);
        $subscriber->onKernelController($this->controllerEvent([
            $this->instanceWithoutConstructor(SettingsController::class),
            'general',
        ]));
    }

    public function testThrowsAccessDeniedWhenCheckerReturnsFalse(): void
    {
        $checker = $this->createMock(UptimeMonitorAccessCheckerInterface::class);
        $checker->method('canAccessDashboard')->willReturn(false);

        $subscriber = new UptimeMonitorAccessSubscriber($checker, false);

        $this->expectException(AccessDeniedException::class);
        $subscriber->onKernelController($this->controllerEvent([
            $this->instanceWithoutConstructor(DashboardController::class),
            'index',
        ]));
    }

    public function testTenantCreationRequiresManageSettings(): void
    {
        $checker = $this->createMock(UptimeMonitorAccessCheckerInterface::class);
        $checker->method('canAccessDashboard')->willReturn(true);
        $checker->expects(self::once())->method('canManageSettings')->willReturn(false);

        $subscriber = new UptimeMonitorAccessSubscriber($checker, false);

        // Read-only dashboard users must not be able to create tenants (GET form or POST submit).
        $this->expectException(AccessDeniedException::class);
        $subscriber->onKernelController($this->controllerEvent([
            $this->instanceWithoutConstructor(TenantController::class),
            'new',
        ], 'POST'));
    }

    public function testTenantCreationFormGetRequiresManageSettings(): void
    {
        $checker = $this->createMock(UptimeMonitorAccessCheckerInterface::class);
        $checker->expects(self::never())->method('canAccessDashboard');
        $checker->expects(self::once())->method('canManageSettings')->willReturn(true);

        $subscriber = new UptimeMonitorAccessSubscriber($checker, false);
        $subscriber->onKernelController($this->controllerEvent([
            $this->instanceWithoutConstructor(TenantController::class),
            'new',
        ]));
    }

    public function testTenantIndexUsesDashboardAccess(): void
    {
        $checker = $this->createMock(UptimeMonitorAccessCheckerInterface::class);
        $checker->expects(self::once())->method('canAccessDashboard')->willReturn(true);
        $checker->expects(self::never())->method('canManageSettings');

        $subscriber = new UptimeMonitorAccessSubscriber($checker, false);
        $subscriber->onKernelController($this->controllerEvent([
            $this->instanceWithoutConstructor(TenantController::class),
            'index',
        ]));
    }

    public function testUnmappedUnsafeMethodFailsClosedToManageSettings(): void
    {
        $checker = $this->createMock(UptimeMonitorAccessCheckerInterface::class);
        $checker->method('canAccessDashboard')->willReturn(true);
        $checker->expects(self::once())->method('canManageSettings')->willReturn(false);

        $subscriber = new UptimeMonitorAccessSubscriber($checker, false);

        $this->expectException(AccessDeniedException::class);
        $subscriber->onKernelController($this->controllerEvent([
            $this->instanceWithoutConstructor(DashboardController::class),
            'index',
        ], 'POST'));
    }

    public function testTenantAwareCheckerDecidesForTheTenantInTheUrl(): void
    {
        $checker = $this->createMock(TenantAwareUptimeMonitorAccessCheckerInterface::class);
        $checker->expects(self::never())->method('canAccessDashboard');
        $checker->expects(self::exactly(2))->method('canAccessTenant')->with(self::anything(), 'acme')->willReturn(true);
        $checker->expects(self::once())->method('canManageTenantMonitors')->with(self::anything(), 'acme')->willReturn(true);
        $checker->expects(self::exactly(2))->method('canManageTenantSettings')->with(self::anything(), 'acme')->willReturn(true);
        $subscriber = new UptimeMonitorAccessSubscriber($checker, false);

        $subscriber->onKernelController($this->controllerEvent([$this->instanceWithoutConstructor(DashboardController::class), 'index'], tenant: 'acme'));
        $subscriber->onKernelController($this->controllerEvent([$this->instanceWithoutConstructor(MonitorController::class), 'show'], tenant: 'acme'));
        $subscriber->onKernelController($this->controllerEvent([$this->instanceWithoutConstructor(MonitorController::class), 'edit'], 'POST', 'acme'));
        $subscriber->onKernelController($this->controllerEvent([$this->instanceWithoutConstructor(SettingsController::class), 'index'], tenant: 'acme'));
        $subscriber->onKernelController($this->controllerEvent([$this->instanceWithoutConstructor(DashboardController::class), 'index'], 'POST', 'acme'));
    }

    public function testTenantAwareCheckerKeepsTheGlobalMethodsWithoutATenant(): void
    {
        $checker = $this->createMock(TenantAwareUptimeMonitorAccessCheckerInterface::class);
        $checker->expects(self::never())->method('canAccessTenant');
        $checker->expects(self::once())->method('canAccessDashboard')->willReturn(true);
        $checker->expects(self::once())->method('canManageMonitors')->willReturn(true);
        $subscriber = new UptimeMonitorAccessSubscriber($checker, false);

        $subscriber->onKernelController($this->controllerEvent([$this->instanceWithoutConstructor(TenantController::class), 'index']));
        $subscriber->onKernelController($this->controllerEvent([$this->instanceWithoutConstructor(MonitorController::class), 'new'], 'POST'));
    }

    public function testAMonitorAwareCheckerDecidesTheReadsOfOneMonitor(): void
    {
        $monitor = new Monitor(new Tenant('user-1', 'Ana'), 'API', MonitorType::Https, 'https://x.test');
        $checker = $this->createMock(MonitorAwareUptimeMonitorAccessCheckerInterface::class);
        $checker->expects(self::never())->method('canAccessTenant');
        $checker->expects(self::exactly(3))->method('canViewMonitor')->with(self::anything(), $monitor)->willReturn(true);
        $subscriber = new UptimeMonitorAccessSubscriber($checker, false, null, true, $this->monitors($monitor));

        foreach ([[MonitorController::class, 'show'], [HistoryApiController::class, 'monitorHistory'], [AggregatesApiController::class, 'monitorAggregates']] as [$class, $method]) {
            $subscriber->onKernelController($this->monitorEvent([$this->instanceWithoutConstructor($class), $method], 'user-1', '7'));
        }
    }

    public function testAMonitorTheUserMayNotViewIsNotFound(): void
    {
        $monitor = new Monitor(new Tenant('user-1', 'Ana'), 'API', MonitorType::Https, 'https://x.test');
        $checker = $this->createStub(MonitorAwareUptimeMonitorAccessCheckerInterface::class);
        $checker->method('canViewMonitor')->willReturn(false);
        $subscriber = new UptimeMonitorAccessSubscriber($checker, false, null, true, $this->monitors($monitor));

        $this->expectException(NotFoundHttpException::class);
        $subscriber->onKernelController($this->monitorEvent([$this->instanceWithoutConstructor(MonitorController::class), 'show'], 'user-1', '7'));
    }

    public function testAMonitorOfAnotherTenantIsNotFoundEvenIfViewable(): void
    {
        $monitor = new Monitor(new Tenant('user-2', 'Bob'), 'API', MonitorType::Https, 'https://x.test');
        $checker = $this->createStub(MonitorAwareUptimeMonitorAccessCheckerInterface::class);
        $checker->method('canViewMonitor')->willReturn(true);
        $subscriber = new UptimeMonitorAccessSubscriber($checker, false, null, true, $this->monitors($monitor));

        $this->expectException(NotFoundHttpException::class);
        $subscriber->onKernelController($this->monitorEvent([$this->instanceWithoutConstructor(MonitorController::class), 'show'], 'user-1', '7'));
    }

    public function testAnUnknownOrMissingMonitorIsNotFound(): void
    {
        $checker = $this->createStub(MonitorAwareUptimeMonitorAccessCheckerInterface::class);
        $checker->method('canViewMonitor')->willReturn(true);
        $subscriber = new UptimeMonitorAccessSubscriber($checker, false);

        try {
            $subscriber->onKernelController($this->monitorEvent([$this->instanceWithoutConstructor(MonitorController::class), 'show'], 'user-1', 'x'));
            self::fail('a non-numeric id is not found');
        } catch (NotFoundHttpException) {
        }

        $this->expectException(NotFoundHttpException::class);
        $subscriber->onKernelController($this->monitorEvent([$this->instanceWithoutConstructor(MonitorController::class), 'show'], 'user-1', '7'));
    }

    public function testAMonitorAwareCheckerKeepsTheTenantMethodsElsewhere(): void
    {
        $checker = $this->createMock(MonitorAwareUptimeMonitorAccessCheckerInterface::class);
        $checker->expects(self::never())->method('canViewMonitor');
        $checker->expects(self::once())->method('canAccessTenant')->willReturn(true);
        $checker->expects(self::once())->method('canManageTenantMonitors')->willReturn(true);
        $subscriber = new UptimeMonitorAccessSubscriber($checker, false);

        $subscriber->onKernelController($this->monitorEvent([$this->instanceWithoutConstructor(DashboardController::class), 'index'], 'user-1', null));
        $subscriber->onKernelController($this->monitorEvent([$this->instanceWithoutConstructor(MonitorController::class), 'edit'], 'user-1', '7'));
    }

    public function testByDefaultATenantTheUserMayNotAccessIsForbidden(): void
    {
        $checker = $this->createMock(TenantAwareUptimeMonitorAccessCheckerInterface::class);
        $checker->expects(self::once())->method('canAccessTenant')->with(self::anything(), 'user-1')->willReturn(false);
        $subscriber = new UptimeMonitorAccessSubscriber($checker, false);

        $this->expectException(AccessDeniedException::class);
        $subscriber->onKernelController($this->controllerEvent([$this->instanceWithoutConstructor(DashboardController::class), 'index'], tenant: 'user-1'));
    }

    public function testDeniedTenantStatus404HidesATenantTheUserMayNotAccess(): void
    {
        $checker = $this->createStub(TenantAwareUptimeMonitorAccessCheckerInterface::class);
        $checker->method('canAccessTenant')->willReturn(false);
        $subscriber = new UptimeMonitorAccessSubscriber($checker, false, null, true, null, 404);
        $notFound   = 0;

        foreach ([
            [[DashboardController::class, 'index'], 'GET'],
            [[SummaryController::class, 'index'], 'GET'],
            [[SettingsController::class, 'index'], 'GET'],
            [[StatusApiController::class, 'summary'], 'GET'],
            [[MonitorController::class, 'show'], 'GET'],
            [[MonitorController::class, 'edit'], 'POST'],
            [[DashboardController::class, 'index'], 'POST'],
        ] as [[$class, $method], $httpMethod]) {
            try {
                $subscriber->onKernelController($this->controllerEvent([$this->instanceWithoutConstructor($class), $method], $httpMethod, 'user-1'));
                self::fail($class . '::' . $method . ' must be refused');
            } catch (NotFoundHttpException) {
                ++$notFound;
            }
        }
        self::assertSame(7, $notFound);
    }

    public function testDeniedTenantStatus404KeepsForbiddenForAMemberRefusedOneAction(): void
    {
        $checker = $this->createStub(TenantAwareUptimeMonitorAccessCheckerInterface::class);
        $checker->method('canAccessTenant')->willReturn(true);
        $checker->method('canManageTenantMonitors')->willReturn(false);
        $subscriber = new UptimeMonitorAccessSubscriber($checker, false, null, true, null, 404);

        $this->expectException(AccessDeniedException::class);
        $subscriber->onKernelController($this->controllerEvent([$this->instanceWithoutConstructor(MonitorController::class), 'edit'], 'POST', 'acme'));
    }

    public function testDeniedTenantStatus404LeavesTheGlobalChecksForbidden(): void
    {
        $checker = $this->createStub(UptimeMonitorAccessCheckerInterface::class);
        $checker->method('canAccessDashboard')->willReturn(false);
        $subscriber = new UptimeMonitorAccessSubscriber($checker, false, null, true, null, 404);

        $this->expectException(AccessDeniedException::class);
        $subscriber->onKernelController($this->controllerEvent([$this->instanceWithoutConstructor(DashboardController::class), 'index'], tenant: 'user-1'));
    }

    public function testDeniedTenantStatus404KeepsTheSharedMonitorReadable(): void
    {
        $monitor = new Monitor(new Tenant('user-1', 'Ana'), 'API', MonitorType::Https, 'https://x.test');
        $checker = $this->createStub(MonitorAwareUptimeMonitorAccessCheckerInterface::class);
        $checker->method('canAccessTenant')->willReturn(false);
        $checker->method('canViewMonitor')->willReturn(true);
        $subscriber = new UptimeMonitorAccessSubscriber($checker, false, null, true, $this->monitors($monitor), 404);

        $subscriber->onKernelController($this->monitorEvent([$this->instanceWithoutConstructor(MonitorController::class), 'show'], 'user-1', '7'));
        $subscriber->onKernelController($this->monitorEvent([$this->instanceWithoutConstructor(HistoryApiController::class), 'monitorHistory'], 'user-1', '7'));

        $this->expectException(NotFoundHttpException::class);
        $subscriber->onKernelController($this->monitorEvent([$this->instanceWithoutConstructor(MonitorController::class), 'togglePause'], 'user-1', '7'));
    }

    private function monitors(Monitor $monitor): MonitorRepository
    {
        $monitors = $this->createStub(MonitorRepository::class);
        $monitors->method('find')->willReturnCallback(static fn (int $id): ?Monitor => $id === 7 ? $monitor : null);

        return $monitors;
    }

    /**
     * @param array{0: object, 1: string} $controller
     */
    private function monitorEvent(array $controller, string $tenant, ?string $id): ControllerEvent
    {
        $event = $this->controllerEvent($controller, 'GET', $tenant);
        if ($id !== null) {
            $event->getRequest()->attributes->set('id', $id);
        }

        return $event;
    }

    /**
     * @param array{0: object|string, 1: string} $controller
     */
    private function controllerEvent(array $controller, string $httpMethod = 'GET', ?string $tenant = null): ControllerEvent
    {
        $kernel  = $this->createMock(HttpKernelInterface::class);
        $request = new Request();
        $request->setMethod($httpMethod);
        if ($tenant !== null) {
            $request->attributes->set('tenantSlug', $tenant);
        }

        // Symfony accepts [controller, method]; PHPStan types ControllerEvent as callable(): mixed only.
        return new ControllerEvent($kernel, $controller, $request, HttpKernelInterface::MAIN_REQUEST); // @phpstan-ignore argument.type
    }

    /**
     * @template T of object
     *
     * @param class-string<T> $class
     *
     * @return T
     */
    private function instanceWithoutConstructor(string $class): object
    {
        return (new ReflectionClass($class))->newInstanceWithoutConstructor();
    }
}
