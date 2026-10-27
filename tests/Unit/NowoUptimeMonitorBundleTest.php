<?php

declare(strict_types=1);

namespace Nowo\UptimeMonitorBundle\Tests\Unit;

use Nowo\UptimeMonitorBundle\DependencyInjection\Compiler\CsrfProtectionPass;
use Nowo\UptimeMonitorBundle\DependencyInjection\Compiler\TwigPathsPass;
use Nowo\UptimeMonitorBundle\DependencyInjection\UptimeMonitorExtension;
use Nowo\UptimeMonitorBundle\UptimeMonitorBundle;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ContainerBuilder;

#[CoversClass(UptimeMonitorBundle::class)]
final class NowoUptimeMonitorBundleTest extends TestCase
{
    public function testGetContainerExtension(): void
    {
        $bundle = new UptimeMonitorBundle();
        self::assertInstanceOf(UptimeMonitorExtension::class, $bundle->getContainerExtension());
    }

    public function testBuildRegistersTwigPathsCompilerPass(): void
    {
        $container = new ContainerBuilder();
        (new UptimeMonitorBundle())->build($container);

        $passes = array_map(static fn (object $pass): string => $pass::class, $container->getCompilerPassConfig()->getBeforeOptimizationPasses());
        self::assertContains(TwigPathsPass::class, $passes);
        self::assertContains(CsrfProtectionPass::class, $passes);
    }
}
