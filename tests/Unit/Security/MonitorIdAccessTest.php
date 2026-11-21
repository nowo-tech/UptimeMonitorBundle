<?php

declare(strict_types=1);

namespace Nowo\UptimeMonitorBundle\Tests\Unit\Security;

use Nowo\UptimeMonitorBundle\Controller\MonitorController;
use Nowo\UptimeMonitorBundle\Entity\Monitor;
use Nowo\UptimeMonitorBundle\Entity\Tenant;
use Nowo\UptimeMonitorBundle\Enum\MonitorType;
use Nowo\UptimeMonitorBundle\EventSubscriber\UptimeMonitorAccessSubscriber;
use Nowo\UptimeMonitorBundle\Repository\MonitorRepository;
use Nowo\UptimeMonitorBundle\Search\MonitorSearchVisibility;
use Nowo\UptimeMonitorBundle\Security\AllowAllUptimeMonitorAccessChecker;
use Nowo\UptimeMonitorBundle\Security\ConfigurableUptimeMonitorAccessChecker;
use Nowo\UptimeMonitorBundle\Security\MonitorAwareUptimeMonitorAccessCheckerInterface;
use Nowo\UptimeMonitorBundle\Security\MonitorReadScope;
use Nowo\UptimeMonitorBundle\Security\MonitorViewDecision;
use Nowo\UptimeMonitorBundle\Tests\Support\IdAndMonitorAwareChecker;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionProperty;
use stdClass;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\ControllerEvent;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;

/**
 * 1.35 (Beacon spec 225): `canViewMonitorId()`, the id form of the monitor-level read, on the built-in checkers and
 * wherever the bundle decides a monitor read.
 */
#[CoversClass(MonitorViewDecision::class)]
#[CoversClass(ConfigurableUptimeMonitorAccessChecker::class)]
#[CoversClass(AllowAllUptimeMonitorAccessChecker::class)]
#[CoversClass(UptimeMonitorAccessSubscriber::class)]
#[CoversClass(MonitorReadScope::class)]
#[CoversClass(MonitorSearchVisibility::class)]
final class MonitorIdAccessTest extends TestCase
{
    public function testTheBuiltInCheckersAnswerTheIdForm(): void
    {
        $auth = $this->createStub(AuthorizationCheckerInterface::class);
        $auth->method('isGranted')->willReturnCallback(static fn (string $role): bool => $role === 'ROLE_VIEWER');

        self::assertTrue((new ConfigurableUptimeMonitorAccessChecker($auth, [], ['ROLE_VIEWER'], ['ROLE_ADMIN'], ['ROLE_ADMIN']))->canViewMonitorId(new stdClass(), 'acme', 7), 'the dashboard roles');
        self::assertFalse((new ConfigurableUptimeMonitorAccessChecker($auth, [], ['ROLE_ADMIN'], ['ROLE_VIEWER'], ['ROLE_VIEWER']))->canViewMonitorId(new stdClass(), 'acme', 7));
        self::assertTrue((new AllowAllUptimeMonitorAccessChecker())->canViewMonitorId(new stdClass(), 'acme', 7));
    }

    public function testTheIdFormWinsWhenTheCheckerHasIt(): void
    {
        $monitor = $this->monitor('acme', 7);
        $checker = $this->createMock(IdAndMonitorAwareChecker::class);
        $checker->expects(self::never())->method('canViewMonitor');
        $checker->expects(self::once())->method('canViewMonitorId')->with(self::anything(), 'acme', 7)->willReturn(true);

        self::assertTrue(MonitorViewDecision::canView($checker, new stdClass(), $monitor));
    }

    public function testTheEntityFormStaysForOtherCheckersAndUnsavedMonitors(): void
    {
        $saved   = $this->monitor('acme', 7);
        $checker = $this->createMock(MonitorAwareUptimeMonitorAccessCheckerInterface::class);
        $checker->expects(self::once())->method('canViewMonitor')->with(self::anything(), $saved)->willReturn(false);
        self::assertFalse(MonitorViewDecision::canView($checker, new stdClass(), $saved));

        $unsaved = new Monitor(new Tenant('acme', 'Acme'), 'API', MonitorType::Https, 'https://x.test');
        $both    = $this->createMock(IdAndMonitorAwareChecker::class);
        $both->expects(self::never())->method('canViewMonitorId');
        $both->expects(self::once())->method('canViewMonitor')->willReturn(true);
        self::assertTrue(MonitorViewDecision::canView($both, new stdClass(), $unsaved));
    }

    public function testTheAccessSubscriberAsksTheIdForm(): void
    {
        $monitor  = $this->monitor('acme', 7);
        $monitors = $this->createStub(MonitorRepository::class);
        $monitors->method('find')->willReturnCallback(static fn (int $id): ?Monitor => $id === 7 ? $monitor : null);
        $checker = $this->createMock(IdAndMonitorAwareChecker::class);
        $checker->expects(self::never())->method('canViewMonitor');
        $checker->method('canViewMonitorId')->willReturnCallback(static fn (object $user, string $slug, int $id): bool => $slug === 'acme' && $id === 7);
        $subscriber = new UptimeMonitorAccessSubscriber($checker, false, null, true, $monitors);

        $subscriber->onKernelController($this->showEvent('acme', '7'));

        $this->expectException(NotFoundHttpException::class);
        $subscriber->onKernelController($this->showEvent('other', '7'));
    }

    public function testTheReadScopeAndTheSearchAskTheIdForm(): void
    {
        $monitor = $this->monitor('acme', 9);
        $checker = $this->createMock(IdAndMonitorAwareChecker::class);
        $checker->expects(self::never())->method('canViewMonitor');
        $checker->expects(self::exactly(2))->method('canViewMonitorId')->with(self::anything(), 'acme', 9)->willReturn(true);

        self::assertTrue((new MonitorReadScope($checker))->canView($monitor));
        self::assertTrue((new MonitorSearchVisibility($checker))->canSee($monitor));
    }

    private function monitor(string $slug, int $id): Monitor
    {
        $monitor = new Monitor(new Tenant($slug, 'Tenant'), 'API', MonitorType::Https, 'https://x.test');
        (new ReflectionProperty(Monitor::class, 'id'))->setValue($monitor, $id);

        return $monitor;
    }

    private function showEvent(string $tenant, string $id, string $method = 'show'): ControllerEvent
    {
        $request    = new Request(attributes: ['tenantSlug' => $tenant, 'id' => $id]);
        $controller = (new ReflectionClass(MonitorController::class))->newInstanceWithoutConstructor();

        // Symfony accepts [controller, method] (the subscriber reads that form); PHPStan types it as callable(): mixed only.
        return new ControllerEvent($this->createStub(HttpKernelInterface::class), [$controller, $method], $request, HttpKernelInterface::MAIN_REQUEST); // @phpstan-ignore argument.type
    }
}
