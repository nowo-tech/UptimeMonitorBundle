<?php

declare(strict_types=1);

namespace Nowo\UptimeMonitorBundle\Tests\Unit\Security;

use Nowo\UptimeMonitorBundle\Security\ConfigurableUptimeMonitorAccessChecker;
use PHPUnit\Framework\TestCase;
use stdClass;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;

/**
 * @covers \Nowo\UptimeMonitorBundle\Security\ConfigurableUptimeMonitorAccessChecker
 */
final class ConfigurableUptimeMonitorAccessCheckerTest extends TestCase
{
    public function testGrantsWhenAreaRoleMatches(): void
    {
        $auth = $this->createMock(AuthorizationCheckerInterface::class);
        $auth->method('isGranted')->willReturnCallback(static fn (string $role): bool => $role === 'ROLE_ADMIN');

        $checker = new ConfigurableUptimeMonitorAccessChecker(
            $auth,
            ['ROLE_ADMIN'],
            ['ROLE_ADMIN'],
            ['ROLE_OPERATOR'],
            ['ROLE_SETTINGS'],
        );
        $user = new stdClass();

        self::assertTrue($checker->canAccessDashboard($user));
        self::assertFalse($checker->canManageMonitors($user));
        self::assertFalse($checker->canManageSettings($user));
    }

    public function testDeniesWhenAccessRolesFailEvenIfAreaRolesWouldPass(): void
    {
        $auth = $this->createMock(AuthorizationCheckerInterface::class);
        $auth->method('isGranted')->willReturnCallback(static fn (string $role): bool => $role === 'ROLE_VIEWER');

        $checker = new ConfigurableUptimeMonitorAccessChecker(
            $auth,
            ['ROLE_ADMIN'],
            ['ROLE_VIEWER'],
            ['ROLE_VIEWER'],
            ['ROLE_VIEWER'],
        );
        $user = new stdClass();

        self::assertFalse($checker->canAccessDashboard($user));
        self::assertFalse($checker->canManageMonitors($user));
        self::assertFalse($checker->canManageSettings($user));
    }

    public function testEmptyAccessRolesDoNotBlockAreaChecks(): void
    {
        $auth = $this->createMock(AuthorizationCheckerInterface::class);
        $auth->method('isGranted')->willReturnCallback(static fn (string $role): bool => $role === 'ROLE_OPS');

        $checker = new ConfigurableUptimeMonitorAccessChecker(
            $auth,
            [],
            ['ROLE_OPS'],
            ['ROLE_OPS'],
            ['ROLE_OTHER'],
        );
        $user = new stdClass();

        self::assertTrue($checker->canAccessDashboard($user));
        self::assertTrue($checker->canManageMonitors($user));
        self::assertFalse($checker->canManageSettings($user));
    }

    public function testEmptyAreaRolesAllowWhenAccessGatePasses(): void
    {
        $auth = $this->createMock(AuthorizationCheckerInterface::class);
        $auth->method('isGranted')->willReturn(true);

        $checker = new ConfigurableUptimeMonitorAccessChecker($auth, ['ROLE_ADMIN'], [], [], []);
        $user    = new stdClass();

        self::assertTrue($checker->canAccessDashboard($user));
        self::assertTrue($checker->canManageMonitors($user));
        self::assertTrue($checker->canManageSettings($user));
    }
}
