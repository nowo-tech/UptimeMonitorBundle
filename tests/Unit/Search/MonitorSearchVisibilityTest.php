<?php

declare(strict_types=1);

namespace Nowo\UptimeMonitorBundle\Tests\Unit\Search;

use Nowo\UptimeMonitorBundle\Entity\Monitor;
use Nowo\UptimeMonitorBundle\Entity\Tenant;
use Nowo\UptimeMonitorBundle\Enum\MonitorType;
use Nowo\UptimeMonitorBundle\Search\MonitorSearchVisibility;
use Nowo\UptimeMonitorBundle\Security\TenantAwareUptimeMonitorAccessCheckerInterface;
use Nowo\UptimeMonitorBundle\Security\UptimeMonitorAccessCheckerInterface;
use Nowo\UptimeMonitorBundle\Tests\Support\SharedMonitorAccessChecker;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorage;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Security\Core\User\InMemoryUser;

#[CoversClass(MonitorSearchVisibility::class)]
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
        $checker->method('canAccessTenant')->willReturnCallback(static function (object $given, string $slug) use ($user): bool {
            return $given === $user && $slug === 'acme';
        });

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

    private function monitor(string $tenant, string $name): Monitor
    {
        return new Monitor(new Tenant($tenant, $tenant), $name, MonitorType::Https, 'https://example.com');
    }
}
