<?php

declare(strict_types=1);

namespace Nowo\UptimeMonitorBundle\Tests\Unit;

use Nowo\UptimeMonitorBundle\DependencyInjection\UptimeMonitorExtension;
use Nowo\UptimeMonitorBundle\UptimeMonitorBundle;
use PHPUnit\Framework\TestCase;

/**
 * @covers \Nowo\UptimeMonitorBundle\UptimeMonitorBundle
 */
final class NowoUptimeMonitorBundleTest extends TestCase
{
    public function testGetContainerExtension(): void
    {
        $bundle = new UptimeMonitorBundle();
        self::assertInstanceOf(UptimeMonitorExtension::class, $bundle->getContainerExtension());
    }
}
