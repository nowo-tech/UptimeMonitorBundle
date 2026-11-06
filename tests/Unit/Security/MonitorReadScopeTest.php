<?php

declare(strict_types=1);

namespace Nowo\UptimeMonitorBundle\Tests\Unit\Security;

use Nowo\UptimeMonitorBundle\Entity\Monitor;
use Nowo\UptimeMonitorBundle\Entity\Tenant;
use Nowo\UptimeMonitorBundle\Enum\MonitorType;
use Nowo\UptimeMonitorBundle\Security\MonitorAwareUptimeMonitorAccessCheckerInterface;
use Nowo\UptimeMonitorBundle\Security\MonitorReadScope;
use Nowo\UptimeMonitorBundle\Security\TenantAwareUptimeMonitorAccessCheckerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use stdClass;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorage;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Security\Core\User\InMemoryUser;

#[CoversClass(MonitorReadScope::class)]
final class MonitorReadScopeTest extends TestCase
{
    private Monitor $monitor;

    protected function setUp(): void
    {
        $this->monitor = new Monitor(new Tenant('main', 'Main'), 'API', MonitorType::Https, 'https://x.test');
    }

    public function testAMonitorAwareCheckerDecidesWithTheCurrentUser(): void
    {
        $user    = new InMemoryUser('bob', null);
        $storage = new TokenStorage();
        $storage->setToken(new UsernamePasswordToken($user, 'main'));
        $checker = $this->createMock(MonitorAwareUptimeMonitorAccessCheckerInterface::class);
        $checker->expects(self::once())->method('canAccessTenant')->with($user, 'main')->willReturn(false);
        $checker->expects(self::once())->method('canViewMonitor')->with($user, $this->monitor)->willReturn(true);

        $scope = new MonitorReadScope($checker, $storage);

        self::assertFalse($scope->seesTenant('main'));
        self::assertTrue($scope->canView($this->monitor));
    }

    public function testNoUserIsAnAnonymousObject(): void
    {
        $checker = $this->createMock(MonitorAwareUptimeMonitorAccessCheckerInterface::class);
        $checker->expects(self::once())->method('canViewMonitor')->with(self::isInstanceOf(stdClass::class))->willReturn(false);

        self::assertFalse((new MonitorReadScope($checker))->canView($this->monitor));
    }

    public function testAnyOtherCheckerNarrowsNothing(): void
    {
        $checker = $this->createMock(TenantAwareUptimeMonitorAccessCheckerInterface::class);
        $checker->expects(self::never())->method('canAccessTenant');

        $scope = new MonitorReadScope($checker);

        self::assertTrue($scope->seesTenant('main'));
        self::assertTrue($scope->canView($this->monitor));
    }

    public function testAllowUnauthenticatedNarrowsNothing(): void
    {
        $checker = $this->createMock(MonitorAwareUptimeMonitorAccessCheckerInterface::class);
        $checker->expects(self::never())->method('canAccessTenant');
        $checker->expects(self::never())->method('canViewMonitor');

        $scope = new MonitorReadScope($checker, null, true);

        self::assertTrue($scope->seesTenant('main'));
        self::assertTrue($scope->canView($this->monitor));
    }
}
