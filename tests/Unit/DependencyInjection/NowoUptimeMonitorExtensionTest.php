<?php

declare(strict_types=1);

namespace Nowo\UptimeMonitorBundle\Tests\Unit\DependencyInjection;

use LogicException;
use Nowo\UptimeMonitorBundle\DependencyInjection\Configuration;
use Nowo\UptimeMonitorBundle\DependencyInjection\UptimeMonitorExtension;
use Nowo\UptimeMonitorBundle\Security\AllowAllUptimeMonitorAccessChecker;
use Nowo\UptimeMonitorBundle\Security\ConfigurableUptimeMonitorAccessChecker;
use Nowo\UptimeMonitorBundle\Security\UptimeMonitorAccessCheckerInterface;
use Nowo\UptimeMonitorBundle\Tests\Unit\Support\FakeDoctrineExtension;
use Nowo\UptimeMonitorBundle\Tests\Unit\Support\FakeFrameworkExtension;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\SecurityBundle\SecurityBundle;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Extension\Extension;

/**
 * @covers \Nowo\UptimeMonitorBundle\DependencyInjection\UptimeMonitorExtension
 */
final class NowoUptimeMonitorExtensionTest extends TestCase
{
    public function testAliasAndParameters(): void
    {
        $container = $this->containerWithSecurityBundle();
        $extension = new UptimeMonitorExtension();
        $extension->load([['retention' => ['detail_days' => 14]]], $container);

        self::assertSame(Configuration::ALIAS, $extension->getAlias());
        self::assertTrue($container->getParameter('nowo_uptime_monitor.enabled'));
        /** @var array<string, mixed> $templates */
        $templates = $container->getParameter('nowo_uptime_monitor.templates');
        self::assertSame('@NowoUptimeMonitorBundle/layout.html.twig', $templates['layout']);
        /** @var array<string, mixed> $retention */
        $retention = $container->getParameter('nowo_uptime_monitor.retention');
        self::assertSame(14, $retention['detail_days']);
        self::assertFalse($container->getParameter('nowo_uptime_monitor.security.allow_unauthenticated'));
        self::assertTrue($container->hasAlias(UptimeMonitorAccessCheckerInterface::class));
        self::assertSame(
            ConfigurableUptimeMonitorAccessChecker::class,
            $container->getDefinition('nowo_uptime_monitor.access_checker.default')->getClass(),
        );
    }

    public function testLoadThrowsWhenDashboardRequiresSecurityBundle(): void
    {
        $container = new ContainerBuilder();
        $extension = new UptimeMonitorExtension();

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('requires symfony/security-bundle');

        $extension->load([[
            'dashboard' => ['enabled' => true],
            'security'  => ['allow_unauthenticated' => false],
        ]], $container);
    }

    public function testLoadAllowsMissingSecurityBundleWhenUnauthenticatedAllowed(): void
    {
        $container = new ContainerBuilder();
        $extension = new UptimeMonitorExtension();
        $extension->load([['security' => ['allow_unauthenticated' => true]]], $container);

        self::assertTrue($container->getParameter('nowo_uptime_monitor.security.allow_unauthenticated'));
        self::assertSame(
            AllowAllUptimeMonitorAccessChecker::class,
            $container->getDefinition('nowo_uptime_monitor.access_checker.default')->getClass(),
        );
    }

    public function testLoadUsesCustomAccessCheckerAliasWhenConfigured(): void
    {
        $container = $this->containerWithSecurityBundle();
        $extension = new UptimeMonitorExtension();
        $extension->load([[
            'security' => [
                'allow_unauthenticated' => false,
                'access_checker'        => 'app.uptime_checker',
            ],
        ]], $container);

        self::assertSame('app.uptime_checker', (string) $container->getAlias(UptimeMonitorAccessCheckerInterface::class));
    }

    public function testPrependRegistersDoctrineAndFrameworkConfig(): void
    {
        $container = new ContainerBuilder();
        $container->registerExtension(new FakeFrameworkExtension());
        $container->registerExtension(new FakeDoctrineExtension());

        (new UptimeMonitorExtension())->prepend($container);

        $frameworkConfigs = $container->getExtensionConfig('framework');
        self::assertNotEmpty($frameworkConfigs);
        self::assertArrayHasKey('translator', $frameworkConfigs[0]);
        self::assertSame(
            '/bundles/uptimemonitor',
            $frameworkConfigs[0]['assets']['packages']['nowo_uptime_monitor']['base_path'],
        );

        $doctrineConfigs = $container->getExtensionConfig('doctrine');
        self::assertNotEmpty($doctrineConfigs);
        self::assertArrayHasKey('orm', $doctrineConfigs[0]);
    }

    public function testPrependSkipsWhenDoctrineMissing(): void
    {
        $container = new ContainerBuilder();
        (new UptimeMonitorExtension())->prepend($container);

        self::assertFalse($container->hasExtension('doctrine'));
    }

    private function containerWithSecurityBundle(): ContainerBuilder
    {
        $container = new ContainerBuilder();
        $container->registerExtension(new class extends Extension {
            public function load(array $configs, ContainerBuilder $container): void
            {
            }

            public function getAlias(): string
            {
                return 'security';
            }
        });
        $container->setParameter('kernel.bundles', ['SecurityBundle' => SecurityBundle::class]);

        return $container;
    }
}
