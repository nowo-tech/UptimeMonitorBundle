<?php

declare(strict_types=1);

namespace Nowo\UptimeMonitorBundle\Tests\Unit\DependencyInjection;

use Nowo\UptimeMonitorBundle\DependencyInjection\Configuration;
use Nowo\UptimeMonitorBundle\DependencyInjection\UptimeMonitorExtension;
use Nowo\UptimeMonitorBundle\Tests\Unit\Support\FakeDoctrineExtension;
use Nowo\UptimeMonitorBundle\Tests\Unit\Support\FakeTwigExtension;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ContainerBuilder;

/**
 * @covers \Nowo\UptimeMonitorBundle\DependencyInjection\UptimeMonitorExtension
 */
final class NowoUptimeMonitorExtensionTest extends TestCase
{
    public function testAliasAndParameters(): void
    {
        $container = new ContainerBuilder();
        $extension = new UptimeMonitorExtension();
        $extension->load([['retention' => ['detail_days' => 14]]], $container);

        self::assertSame(Configuration::ALIAS, $extension->getAlias());
        self::assertTrue($container->getParameter('nowo_uptime_monitor.enabled'));
        self::assertSame('@NowoUptimeMonitorBundle/layout.html.twig', $container->getParameter('nowo_uptime_monitor.templates')['layout']);
        /** @var array<string, mixed> $retention */
        $retention = $container->getParameter('nowo_uptime_monitor.retention');
        self::assertSame(14, $retention['detail_days']);
    }

    public function testPrependRegistersDoctrineAndTwigConfig(): void
    {
        $container = new ContainerBuilder();
        $container->registerExtension(new FakeDoctrineExtension());
        $container->registerExtension(new FakeTwigExtension());

        (new UptimeMonitorExtension())->prepend($container);

        $doctrineConfigs = $container->getExtensionConfig('doctrine');
        self::assertNotEmpty($doctrineConfigs);
        self::assertArrayHasKey('orm', $doctrineConfigs[0]);

        $twigConfigs = $container->getExtensionConfig('twig');
        self::assertNotEmpty($twigConfigs);
    }

    public function testPrependSkipsWhenDoctrineMissing(): void
    {
        $container = new ContainerBuilder();
        (new UptimeMonitorExtension())->prepend($container);

        self::assertFalse($container->hasExtension('doctrine'));
    }
}
