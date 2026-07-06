<?php

declare(strict_types=1);

namespace Nowo\UptimeMonitorBundle\Tests\Unit\Security;

use Nowo\UptimeMonitorBundle\Security\MonitorUrlSsrfGuard;
use PHPUnit\Framework\TestCase;

final class MonitorUrlSsrfGuardTest extends TestCase
{
    public function testBlocksPrivateNetwork(): void
    {
        $guard = new MonitorUrlSsrfGuard();

        self::assertTrue($guard->isBlocked('http://127.0.0.1/'));
        self::assertTrue($guard->isBlocked('http://169.254.169.254/'));
    }

    public function testAllowsPublicHost(): void
    {
        $guard = new MonitorUrlSsrfGuard();

        self::assertFalse($guard->isBlocked('https://example.com/health'));
    }
}
