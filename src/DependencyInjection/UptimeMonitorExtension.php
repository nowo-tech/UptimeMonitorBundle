<?php

declare(strict_types=1);

namespace Nowo\UptimeMonitorBundle\DependencyInjection;

use LogicException;
use Nowo\UptimeMonitorBundle\Security\AllowAllUptimeMonitorAccessChecker;
use Nowo\UptimeMonitorBundle\Security\ConfigurableUptimeMonitorAccessChecker;
use Nowo\UptimeMonitorBundle\Security\UptimeMonitorAccessCheckerInterface;
use Symfony\Component\Config\FileLocator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Extension\Extension;
use Symfony\Component\DependencyInjection\Extension\PrependExtensionInterface;
use Symfony\Component\DependencyInjection\Loader\YamlFileLoader;

use function array_key_exists;
use function is_array;
use function is_string;

/**
 * Loads bundle configuration and registers services.
 */
final class UptimeMonitorExtension extends Extension implements PrependExtensionInterface
{
    public function prepend(ContainerBuilder $container): void
    {
        $this->prependFormKitDefaults($container);
        if ($container->hasExtension('framework')) {
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
        }

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

        $this->prependUiKitDefaults($container);
    }

    /**
     * Seed nowo_ui_kit.css_framework from ui.framework when the host has not set UiKit (REQ-UI-001-kit).
     */

    /**
     * When FormKit is installed, register the uptime_monitor profile. Forms select it via #[FormKitConfig].
     */
    private function prependFormKitDefaults(ContainerBuilder $container): void
    {
        if (!$container->hasExtension('nowo_form_kit')) {
            return;
        }

        $hostHasCssFramework = false;
        $hostHasProfile      = false;
        foreach ($container->getExtensionConfig('nowo_form_kit') as $cfg) {
            /** @var array<string, mixed> $cfg */
            if (array_key_exists('css_framework', $cfg)) {
                $hostHasCssFramework = true;
            }
            $profiles = $cfg['profiles'] ?? null;
            if (is_array($profiles) && array_key_exists('uptime_monitor', $profiles)) {
                $hostHasProfile = true;
            }
        }

        $seed = [];

        if (!$hostHasCssFramework) {
            $seed['css_framework'] = 'bootstrap';
        }

        if (!$hostHasProfile) {
            $seed['profiles'] = [
                'uptime_monitor' => [
                    'alias'              => 'uptime_monitor',
                    'translation_domain' => 'NowoUptimeMonitorBundle',
                    'defaults'           => [
                        'attr'     => ['class' => 'nowo-ui-input form-control'],
                        'row_attr' => ['class' => 'mb-2'],
                    ],
                    'field_types' => [
                        'checkbox' => [
                            'attr'     => ['class' => 'form-check-input'],
                            'row_attr' => ['class' => 'form-check mb-2'],
                        ],
                        'choice' => [
                            'attr' => ['class' => 'form-select'],
                        ],
                        'entity' => [
                            'attr' => ['class' => 'form-select'],
                        ],
                        'file' => [
                            'attr' => ['class' => 'nowo-ui-input form-control'],
                        ],
                        'textarea' => [
                            'attr' => ['class' => 'nowo-ui-input form-control'],
                        ],
                    ],
                ],
            ];
        }

        if ($seed !== []) {
            $container->prependExtensionConfig('nowo_form_kit', $seed);
        }
    }

    private function prependUiKitDefaults(ContainerBuilder $container): void
    {
        if (!$container->hasExtension('nowo_ui_kit')) {
            return;
        }

        $hostHasCssFramework = false;
        foreach ($container->getExtensionConfig('nowo_ui_kit') as $cfg) {
            if (is_array($cfg) && array_key_exists('css_framework', $cfg)) {
                $hostHasCssFramework = true;
                break;
            }
        }

        if ($hostHasCssFramework) {
            return;
        }

        $config = $this->processConfiguration(new Configuration(), $container->getExtensionConfig(Configuration::ALIAS));
        $ui     = is_array($config['ui'] ?? null) ? $config['ui'] : [];
        $fw     = (string) ($ui['framework'] ?? 'tabler');
        if ($fw === 'bootstrap') {
            $fw = 'bootstrap5';
        }

        $container->prependExtensionConfig('nowo_ui_kit', [
            'css_framework' => $fw,
        ]);
    }

    public function load(array $configs, ContainerBuilder $container): void
    {
        $loader = new YamlFileLoader($container, new FileLocator(__DIR__ . '/../Resources/config'));
        $loader->load('services.yaml');

        $config = $this->processConfiguration(new Configuration(), $configs);

        if (
            $config['dashboard']['enabled']
            && !$config['security']['allow_unauthenticated']
            && !$this->isSecurityBundleAvailable($container)
        ) {
            throw new LogicException('NowoUptimeMonitorBundle dashboard UI requires symfony/security-bundle when security.allow_unauthenticated is false.');
        }

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
        $container->setParameter(
            Configuration::ALIAS . '.security.allow_unauthenticated',
            $config['security']['allow_unauthenticated'],
        );

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
            if ($security['allow_unauthenticated']) {
                $container->setDefinition(
                    $accessCheckerId,
                    (new Definition(AllowAllUptimeMonitorAccessChecker::class))->setPublic(false),
                );
            } else {
                $container->setDefinition($accessCheckerId, (new Definition(ConfigurableUptimeMonitorAccessChecker::class))
                    ->setAutowired(true)
                    ->setArgument('$accessRoles', $security['access_roles'])
                    ->setArgument('$dashboardRoles', $security['dashboard_roles'])
                    ->setArgument('$manageRoles', $security['manage_roles'])
                    ->setArgument('$settingsRoles', $security['settings_roles']));
            }
        }

        $container->setAlias(UptimeMonitorAccessCheckerInterface::class, $accessCheckerId);
    }

    /**
     * Prefer kernel.bundles: ContainerBuilder::hasExtension() can be false while SecurityBundle
     * is already registered (e.g. during early Flex cache:clear boots).
     */
    private function isSecurityBundleAvailable(ContainerBuilder $container): bool
    {
        if ($container->hasExtension('security')) {
            return true;
        }

        if (!$container->hasParameter('kernel.bundles')) {
            return false;
        }

        /** @var array<string, class-string> $bundles */
        $bundles = $container->getParameter('kernel.bundles');

        return isset($bundles['SecurityBundle']);
    }
}
