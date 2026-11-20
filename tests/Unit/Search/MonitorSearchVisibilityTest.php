<?php

declare(strict_types=1);

namespace Nowo\UptimeMonitorBundle\Tests\Unit\Search;

use Nowo\UptimeMonitorBundle\Entity\Monitor;
use Nowo\UptimeMonitorBundle\Entity\Tenant;
use Nowo\UptimeMonitorBundle\Enum\MonitorType;
use Nowo\UptimeMonitorBundle\Event\MonitorSearchAccessEvent;
use Nowo\UptimeMonitorBundle\Search\MonitorSearchVisibility;
use Nowo\UptimeMonitorBundle\Security\AllowAllUptimeMonitorAccessChecker;
use Nowo\UptimeMonitorBundle\Security\SearchAwareUptimeMonitorAccessCheckerInterface;
use Nowo\UptimeMonitorBundle\Security\TenantAwareUptimeMonitorAccessCheckerInterface;
use Nowo\UptimeMonitorBundle\Security\UptimeMonitorAccessCheckerInterface;
use Nowo\UptimeMonitorBundle\Tests\Support\SharedMonitorAccessChecker;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use stdClass;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorage;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Security\Core\User\InMemoryUser;

#[CoversClass(MonitorSearchVisibility::class)]
#[CoversClass(MonitorSearchAccessEvent::class)]
#[CoversClass(AllowAllUptimeMonitorAccessChecker::class)]
final class MonitorSearchVisibilityTest extends TestCase
{
    public function testMonitorAwareCheckerDecides(): void
    {
        $visibility = new MonitorSearchVisibility(new SharedMonitorAccessChecker());

        self::assertTrue($visibility->canSee($this->monitor('acme', 'Anything')));
        self::assertFalse($visibility->canSee($this->monitor('user-x', 'Private')));
        self::assertTrue($visibility->canSee($this->monitor('user-x', 'Shared one')));
    }

    public function testTenantAwareChecker(): void
    {
        $checker = $this->createMock(TenantAwareUptimeMonitorAccessCheckerInterface::class);
        $user    = new InMemoryUser('u', null);
        $storage = new TokenStorage();
        $storage->setToken(new UsernamePasswordToken($user, 'main'));
        $checker->method('canAccessTenant')->willReturnCallback(static fn (object $given, string $slug): bool => $given === $user && $slug === 'acme');

        $visibility = new MonitorSearchVisibility($checker, $storage);

        self::assertTrue($visibility->canSee($this->monitor('acme', 'A')));
        self::assertFalse($visibility->canSee($this->monitor('other', 'A')));
    }

    public function testGlobalCheckerAndAnonymousUser(): void
    {
        $checker = $this->createMock(UptimeMonitorAccessCheckerInterface::class);
        $checker->expects(self::once())->method('canAccessDashboard')->willReturn(false);

        self::assertFalse((new MonitorSearchVisibility($checker))->canSee($this->monitor('acme', 'A')));
    }

    public function testAllowUnauthenticatedSkipsTheChecks(): void
    {
        $checker = $this->createMock(UptimeMonitorAccessCheckerInterface::class);
        $checker->expects(self::never())->method('canAccessDashboard');

        self::assertTrue((new MonitorSearchVisibility($checker, null, true))->canSee($this->monitor('acme', 'A')));
    }

    public function testCanSearchFollowsTheDashboardCheck(): void
    {
        $checker = $this->createMock(UptimeMonitorAccessCheckerInterface::class);
        $checker->expects(self::once())->method('canAccessDashboard')->willReturn(false);

        self::assertFalse((new MonitorSearchVisibility($checker))->canSearch());
        self::assertTrue((new MonitorSearchVisibility($checker, null, true))->canSearch());
    }

    /**
     * 1.34: the search permission is its own check, denied unless the checker or a listener grants it; the dashboard
     * permission plays no part.
     */
    public function testSearchIsDeniedByDefaultAndGrantedByTheCheckerOrAListener(): void
    {
        $global = $this->createMock(UptimeMonitorAccessCheckerInterface::class);
        $global->expects(self::never())->method('canAccessDashboard');
        self::assertFalse((new MonitorSearchVisibility($global))->canSearchTenant('acme'));

        $seen       = [];
        $dispatcher = new EventDispatcher();
        $dispatcher->addListener(MonitorSearchAccessEvent::class, static function (MonitorSearchAccessEvent $event) use (&$seen): void {
            $seen[] = [$event->tenantSlug, $event->isAllowed(), $event->user::class];
            if ($event->tenantSlug === 'granted') {
                $event->grant();
            }
        });
        $visibility = new MonitorSearchVisibility($global, null, false, $dispatcher);
        self::assertTrue($visibility->canSearchTenant('granted'));
        self::assertFalse($visibility->canSearchTenant('other'));
        self::assertSame([['granted', false, stdClass::class], ['other', false, stdClass::class]], $seen);

        $aware = $this->createMock(SearchAwareUptimeMonitorAccessCheckerInterface::class);
        $aware->method('canSearch')->willReturnCallback(static fn (object $user, string $slug): bool => $slug === 'acme');
        $aware->expects(self::never())->method('canAccessDashboard');
        self::assertTrue((new MonitorSearchVisibility($aware))->canSearchTenant('acme'));
        self::assertFalse((new MonitorSearchVisibility($aware))->canSearchTenant('beta'));

        // A denial wins over a grant.
        $deny = new EventDispatcher();
        $deny->addListener(MonitorSearchAccessEvent::class, static function (MonitorSearchAccessEvent $event): void {
            $event->deny();
        }, 10);
        $deny->addListener(MonitorSearchAccessEvent::class, static function (MonitorSearchAccessEvent $event): void {
            $event->grant();
        });
        self::assertFalse((new MonitorSearchVisibility($aware, null, false, $deny))->canSearchTenant('acme'));

        self::assertTrue((new MonitorSearchVisibility($global, null, true, $deny))->canSearchTenant('any'), 'allow_unauthenticated');
        self::assertTrue((new AllowAllUptimeMonitorAccessChecker())->canSearch(new stdClass(), 'any'));
    }

    private function monitor(string $tenant, string $name): Monitor
    {
        return new Monitor(new Tenant($tenant, $tenant), $name, MonitorType::Https, 'https://example.com');
    }
}
