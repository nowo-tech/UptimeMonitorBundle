<?php

declare(strict_types=1);

namespace Nowo\UptimeMonitorBundle\Tests\Unit\Check;

use Nowo\UptimeMonitorBundle\Check\CheckLimits;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(CheckLimits::class)]
final class CheckLimitsTest extends TestCase
{
    public function testDefaults(): void
    {
        $limits = new CheckLimits();

        self::assertSame(60.0, $limits->maxTimeoutSeconds);
        self::assertSame(1_048_576, $limits->maxBodyBytes);
        self::assertSame(20, $limits->maxRedirects);
    }

    public function testTimeoutIsClampedBetweenOneSecondAndTheMaximum(): void
    {
        $limits = new CheckLimits(30.0);

        self::assertSame(30.0, $limits->clampTimeout(86400.0));
        self::assertSame(12.5, $limits->clampTimeout(12.5));
        self::assertSame(1.0, $limits->clampTimeout(0.0));
        self::assertSame(1.0, (new CheckLimits(0.0))->clampTimeout(10.0));
    }

    public function testRedirectsAreClampedBetweenZeroAndTheMaximum(): void
    {
        $limits = new CheckLimits(maxRedirects: 5);

        self::assertSame(5, $limits->clampRedirects(500));
        self::assertSame(3, $limits->clampRedirects(3));
        self::assertSame(0, $limits->clampRedirects(-1));
    }
}
