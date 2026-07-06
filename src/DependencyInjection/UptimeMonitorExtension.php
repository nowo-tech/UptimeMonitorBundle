<?php

declare(strict_types=1);

namespace Nowo\UptimeMonitorBundle\DependencyInjection;

use Nowo\UptimeMonitorBundle\Security\ConfigurableUptimeMonitorAccessChecker;
use Nowo\UptimeMonitorBundle\Security\UptimeMonitorAccessCheckerInterface;
use Symfony\Component\Config\FileLocator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Extension\Extension;
use Symfony\Component\DependencyInjection\Extension\PrependExtensionInterface;
use Symfony\Component\DependencyInjection\Loader\YamlFileLoader;

use function is_string;

/**
 * Loads bundle configuration and registers services.
 */
final class UptimeMonitorExtension extends Extension implements PrependExtensionInterface
{
    public function prepend(ContainerBuilder $container): void
    {
        if (!$container->hasExtension('framework')) {
            return;
        }

        $container->prependExtensionConfig('framework', [
            'assets' => [
                'packages' => [
                    'nowo_uptime_monitor' => [
                        'base_path' => '/bundles/uptimemonitor',
                    ],
                ],
            ],
            'translator' => [
                'paths'     => [__DIR__ . '/../Resources/translations'],
                'fallbacks' => ['en'],
            ],
            'default_locale'  => 'en',
            'enabled_locales' => ['en', 'es', 'de', 'fr', 'it', 'nl', 'pt'],
        ]);

        if ($container->hasExtension('doctrine')) {
            $container->prependExtensionConfig('doctrine', [
                'orm' => [
                    'mappings' => [
                        'UptimeMonitorBundle' => [
                            'type'      => 'attribute',
                            'is_bundle' => true,
                        ],
                    ],
                ],
            ]);
        }
    }

    public function load(array $configs, ContainerBuilder $container): void
    {
        $loader = new YamlFileLoader($container, new FileLocator(__DIR__ . '/../Resources/config'));
        $loader->load('services.yaml');

        $config = $this->processConfiguration(new Configuration(), $configs);

        $container->setParameter(Configuration::ALIAS . '.enabled', $config['enabled']);
        $container->setParameter(Configuration::ALIAS . '.environments', $config['environments']);
        $container->setParameter(Configuration::ALIAS . '.connection', $config['connection']);
        $container->setParameter(Configuration::ALIAS . '.table_prefix', $config['table_prefix']);
        $container->setParameter(Configuration::ALIAS . '.scheduler', $config['scheduler']);
        $container->setParameter(Configuration::ALIAS . '.checks', $config['checks']);
        $container->setParameter(Configuration::ALIAS . '.checks.block_private_urls', $config['checks']['block_private_urls']);
        $container->setParameter(Configuration::ALIAS . '.retention', $config['retention']);
        $container->setParameter(Configuration::ALIAS . '.aggregates', $config['aggregates']);
        $container->setParameter(Configuration::ALIAS . '.multi_tenant', $config['multi_tenant']);
        $container->setParameter(Configuration::ALIAS . '.tenants', $config['tenants']);
        $container->setParameter(Configuration::ALIAS . '.templates', $config['templates']);
        $container->setParameter(Configuration::ALIAS . '.ui', $config['ui']);
        $container->setParameter(Configuration::ALIAS . '.dashboard', $config['dashboard']);
        $container->setParameter(Configuration::ALIAS . '.dashboard.path', $config['dashboard']['path']);
        $container->setParameter(Configuration::ALIAS . '.status_page', $config['status_page']);
        $container->setParameter(Configuration::ALIAS . '.status_page.path', $config['status_page']['path']);
        $container->setParameter(Configuration::ALIAS . '.notifications', $config['notifications']);
        $container->setParameter(Configuration::ALIAS . '.security', $config['security']);

        $this->registerAccessChecker($container, $config['security']);
    }

    public function getAlias(): string
    {
        return Configuration::ALIAS;
    }

    /** @param array<string, mixed> $security */
    private function registerAccessChecker(ContainerBuilder $container, array $security): void
    {
        $accessCheckerId = $security['access_checker'] ?? null;
        if (!is_string($accessCheckerId) || $accessCheckerId === '') {
            $accessCheckerId = 'nowo_uptime_monitor.access_checker.default';
            $container->setDefinition($accessCheckerId, (new Definition(ConfigurableUptimeMonitorAccessChecker::class))
                ->setAutowired(true)
                ->setArgument('$dashboardRoles', $security['dashboard_roles'])
                ->setArgument('$manageRoles', $security['manage_roles'])
                ->setArgument('$settingsRoles', $security['settings_roles']));
        }

        $container->setAlias(UptimeMonitorAccessCheckerInterface::class, $accessCheckerId);
    }
}
