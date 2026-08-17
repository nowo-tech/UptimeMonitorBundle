<?php

declare(strict_types=1);

namespace Nowo\UptimeMonitorBundle\Tests\Unit\EventSubscriber;

use Nowo\UptimeMonitorBundle\Controller\DashboardController;
use Nowo\UptimeMonitorBundle\Controller\MonitorController;
use Nowo\UptimeMonitorBundle\Controller\SettingsController;
use Nowo\UptimeMonitorBundle\Controller\StatusPageController;
use Nowo\UptimeMonitorBundle\EventSubscriber\UptimeMonitorAccessSubscriber;
use Nowo\UptimeMonitorBundle\Security\UptimeMonitorAccessCheckerInterface;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\ControllerEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

/**
 * @covers \Nowo\UptimeMonitorBundle\EventSubscriber\UptimeMonitorAccessSubscriber
 */
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
        $event      = new ControllerEvent($kernel, static fn () => null, new Request(), HttpKernelInterface::MAIN_REQUEST);

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

    /**
     * @param array{0: object, 1: string} $controller
     */
    private function controllerEvent(array $controller): ControllerEvent
    {
        $kernel = $this->createMock(HttpKernelInterface::class);

        // Symfony accepts [controller, method]; PHPStan types ControllerEvent as callable(): mixed only.
        return new ControllerEvent($kernel, $controller, new Request(), HttpKernelInterface::MAIN_REQUEST); // @phpstan-ignore argument.type
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
