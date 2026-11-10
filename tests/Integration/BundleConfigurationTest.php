<?php

declare(strict_types=1);

namespace Nowo\UptimeMonitorBundle\Tests\Integration;

use Nowo\UptimeMonitorBundle\Apply\MonitorApplier;
use Nowo\UptimeMonitorBundle\Apply\MonitorApplierInterface;
use Nowo\UptimeMonitorBundle\Check\HttpCheckRunner;
use Nowo\UptimeMonitorBundle\Controller\MonitorController;
use Nowo\UptimeMonitorBundle\DependencyInjection\Configuration;
use Nowo\UptimeMonitorBundle\Service\TenantProvisioner;
use Nowo\UptimeMonitorBundle\Service\TenantProvisionerInterface;
use Nowo\UptimeMonitorBundle\Ui\UptimeActions;
use Nowo\UptimeMonitorBundle\Ui\UptimePages;
use Nowo\UptimeMonitorBundle\UptimeMonitorBundle;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Config\Definition\Processor;
use Symfony\Component\Config\FileLocator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\DependencyInjection\Loader\YamlFileLoader;
use Symfony\Component\DependencyInjection\Reference;

#[CoversNothing]
final class BundleConfigurationTest extends TestCase
{
    public function testBundleClassExists(): void
    {
        self::assertTrue(class_exists(UptimeMonitorBundle::class));
    }

    public function testConfigurationTreeProcessesDefaults(): void
    {
        $configuration = new Configuration();
        $configuration->getConfigTreeBuilder();
        $processor = new Processor();

        $config = $processor->processConfiguration($configuration, [[]]);

        self::assertTrue($config['enabled']);
        self::assertSame('/uptime', $config['dashboard']['path']);
        self::assertTrue($config['status_page']['enabled']);
        self::assertSame(['ROLE_ADMIN'], $config['security']['access_roles']);
        self::assertFalse($config['security']['allow_unauthenticated']);
    }

    public function testServicesYamlLoads(): void
    {
        $container = new ContainerBuilder();
        $loader    = new YamlFileLoader(
            $container,
            new FileLocator(__DIR__ . '/../../src/Resources/config'),
        );
        $loader->load('services.yaml');

        self::assertTrue($container->hasDefinition(HttpCheckRunner::class));

        // The page actions get the CSRF token manager and the token storage when the host has them (1.14.1).
        $actions = $container->getDefinition(UptimeActions::class)->getArguments();
        self::assertEquals(new Reference('security.token_storage', ContainerInterface::IGNORE_ON_INVALID_REFERENCE), $actions['$tokenStorage']);
        self::assertEquals(new Reference('security.csrf.token_manager', ContainerInterface::IGNORE_ON_INVALID_REFERENCE), $actions['$csrf']);
        self::assertEquals(new Reference('twig', ContainerInterface::IGNORE_ON_INVALID_REFERENCE), $container->getDefinition(UptimePages::class)->getArguments()['$twig']);

        // DDD ports (1.25): the write entry points behind interfaces, the monitor controller gets the actor's token.
        self::assertSame(MonitorApplier::class, (string) $container->getAlias(MonitorApplierInterface::class));
        self::assertSame(TenantProvisioner::class, (string) $container->getAlias(TenantProvisionerInterface::class));
        $controller = $container->getDefinition(MonitorController::class);
        self::assertEquals(new Reference('security.token_storage', ContainerInterface::IGNORE_ON_INVALID_REFERENCE), $controller->getArguments()['$tokenStorage']);
        self::assertTrue($controller->hasTag('controller.service_arguments'));
        self::assertTrue($controller->hasTag('container.service_subscriber'));
    }
}
