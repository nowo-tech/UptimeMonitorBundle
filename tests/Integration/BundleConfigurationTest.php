<?php

declare(strict_types=1);

namespace Nowo\UptimeMonitorBundle\Tests\Integration;

use Nowo\UptimeMonitorBundle\Check\HttpCheckRunner;
use Nowo\UptimeMonitorBundle\DependencyInjection\Configuration;
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
    }
}
