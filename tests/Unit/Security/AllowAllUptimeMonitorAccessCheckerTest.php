<?php

declare(strict_types=1);

namespace Nowo\UptimeMonitorBundle\Tests\Unit\Security;

use Nowo\UptimeMonitorBundle\Security\AllowAllUptimeMonitorAccessChecker;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use stdClass;

#[CoversClass(AllowAllUptimeMonitorAccessChecker::class)]
final class AllowAllUptimeMonitorAccessCheckerTest extends TestCase
{
    public function testAllowsAnyUserObject(): void
    {
        $checker = new AllowAllUptimeMonitorAccessChecker();
        $user    = new stdClass();

        self::assertTrue($checker->canAccessDashboard($user));
        self::assertTrue($checker->canManageMonitors($user));
        self::assertTrue($checker->canManageSettings($user));
    }
}
