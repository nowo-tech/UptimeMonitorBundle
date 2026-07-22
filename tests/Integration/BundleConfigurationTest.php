<?php

declare(strict_types=1);

namespace Nowo\UptimeMonitorBundle\Tests\Integration;

use Nowo\UptimeMonitorBundle\DependencyInjection\Configuration;
use Nowo\UptimeMonitorBundle\UptimeMonitorBundle;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Config\Definition\Processor;
use Symfony\Component\Config\FileLocator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\YamlFileLoader;

/**
 * @coversNothing
 */
final class BundleConfigurationTest extends TestCase
{
    public function testBundleClassExists(): void
    {
        self::assertTrue(class_exists(UptimeMonitorBundle::class));
    }

    public function testConfigurationTreeProcessesDefaults(): void
    {
        $configuration = new Configuration();
        $tree          = $configuration->getConfigTreeBuilder();
        $processor     = new Processor();

        $config = $processor->processConfiguration($configuration, [[]]);

        self::assertTrue($config['enabled']);
        self::assertSame('/uptime', $config['dashboard']['path']);
        self::assertTrue($config['status_page']['enabled']);
    }

    public function testServicesYamlLoads(): void
    {
        $container = new ContainerBuilder();
        $loader    = new YamlFileLoader(
            $container,
            new FileLocator(__DIR__ . '/../../src/Resources/config'),
        );
        $loader->load('services.yaml');

        self::assertTrue($container->hasDefinition('Nowo\UptimeMonitorBundle\Check\HttpCheckRunner'));
    }
}
