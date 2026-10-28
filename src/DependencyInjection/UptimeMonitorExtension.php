<?php

declare(strict_types=1);

namespace Nowo\UptimeMonitorBundle\DependencyInjection;

use LogicException;
use Nowo\UptimeMonitorBundle\Doctrine\TablePrefixListener;
use Nowo\UptimeMonitorBundle\Limits\ConfigurableTenantLimits;
use Nowo\UptimeMonitorBundle\Limits\TenantLimitsInterface;
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
use function rtrim;

/**
 * Loads bundle configuration and registers services.
 */
final class UptimeMonitorExtension extends Extension implements PrependExtensionInterface
{
    /**
     * Prepends FormKit, framework, Twig and UiKit defaults the bundle relies on.
     */
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
                // No translator / default_locale / enabled_locales: prepended values merge into the host's
                // and would override its translator enablement, fallback order and locale setup. The bundle
                // catalogue (src/Resources/translations: en, es, de, fr, it, nl, pt) is auto-discovered.
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
            // REQ-UI-003: no framework classes in the bundle forms (the UiKit form theme of web_ui.form_themes
            // applies the theme slots); convention help / placeholder keys only when the catalogue has them.
            $seed['profiles'] = [
                'uptime_monitor' => [
                    'alias'                           => 'uptime_monitor',
                    'translation_domain'              => 'NowoUptimeMonitorBundle',
                    'convention_requires_translation' => true,
                    'defaults'                        => [
                        'attr'     => [],
                        'row_attr' => [],
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
            if (array_key_exists('css_framework', $cfg)) {
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

    /**
     * Loads the bundle services and exposes the processed configuration as `nowo_uptime_monitor.*` parameters.
     *
     * @param array<int, array<string, mixed>> $configs Raw configuration from every config file
     */
    public function load(array $configs, ContainerBuilder $container): void
    {
        $loader = new YamlFileLoader($container, new FileLocator(__DIR__ . '/../Resources/config'));
        $loader->load('services.yaml');

        $config = $this->resolveCanonicalKeys($this->processConfiguration(new Configuration(), $configs));

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
        $container->setParameter(Configuration::ALIAS . '.doctrine.table_prefix', $config['doctrine']['table_prefix']);
        // enabled: false pauses monitoring: no scheduled checks, and DueChecksRunner is a no-op.
        $container->setParameter(Configuration::ALIAS . '.scheduler', $config['enabled'] ? $config['scheduler'] : ['enabled' => false] + $config['scheduler']);
        $container->setParameter(Configuration::ALIAS . '.checks', $config['checks']);
        $container->setParameter(Configuration::ALIAS . '.checks.block_private_urls', $config['checks']['block_private_urls']);
        foreach (['max_timeout_seconds', 'max_body_bytes', 'max_redirects', 'max_scan_seconds'] as $limit) {
            $container->setParameter(Configuration::ALIAS . '.checks.' . $limit, $config['checks'][$limit]);
        }
        $container->setParameter(Configuration::ALIAS . '.retention', $config['retention']);
        $container->setParameter(Configuration::ALIAS . '.aggregates', $config['aggregates']);
        $container->setParameter(Configuration::ALIAS . '.multi_tenant', $config['multi_tenant']);
        $container->setParameter(Configuration::ALIAS . '.tenants', $config['tenants']);
        $container->setParameter(Configuration::ALIAS . '.templates', $config['templates']);
        $container->setParameter(Configuration::ALIAS . '.web_ui', $config['web_ui']);
        $container->setParameter(Configuration::ALIAS . '.web_ui.enabled', (bool) $config['web_ui']['enabled']);
        $container->setParameter(Configuration::ALIAS . '.ui', $config['ui']);
        $container->setParameter(Configuration::ALIAS . '.dashboard', $config['dashboard']);
        $container->setParameter(Configuration::ALIAS . '.dashboard.path', $config['dashboard']['path']);
        $container->setParameter(Configuration::ALIAS . '.dashboard.tenant_paths', $config['dashboard']['tenant_paths']);
        $container->setParameter(Configuration::ALIAS . '.form.hidden_fields', $config['form']['hidden_fields']);
        $container->setParameter(Configuration::ALIAS . '.status_page', $config['status_page']);
        $container->setParameter(Configuration::ALIAS . '.push', $config['push']);
        $container->setParameter(Configuration::ALIAS . '.push.path', rtrim((string) $config['push']['path'], '/'));
        $container->setParameter(Configuration::ALIAS . '.status_page.path', $config['status_page']['path']);
        $container->setParameter(Configuration::ALIAS . '.notifications', $config['notifications']);
        $container->setParameter(Configuration::ALIAS . '.security', $config['security']);
        $container->setParameter(
            Configuration::ALIAS . '.security.allow_unauthenticated',
            $config['security']['allow_unauthenticated'],
        );

        $this->registerAccessChecker($container, $config['security'], (bool) $config['web_ui']['enabled']);
        $this->registerTenantLimits($container, $config['limits']);
        $this->registerTablePrefixListener($container);
    }

    /**
     * Aliases {@see TenantLimitsInterface} to `limits.provider`, else to the configured {@see ConfigurableTenantLimits}.
     *
     * @param array{provider: ?string, max_monitors: int, min_interval_seconds: int} $limits
     */
    private function registerTenantLimits(ContainerBuilder $container, array $limits): void
    {
        $providerId = $limits['provider'];
        if (!is_string($providerId) || $providerId === '') {
            $providerId = 'nowo_uptime_monitor.tenant_limits.default';
            $container->setDefinition($providerId, (new Definition(ConfigurableTenantLimits::class))
                ->setArgument('$maxMonitors', $limits['max_monitors'])
                ->setArgument('$minIntervalSeconds', $limits['min_interval_seconds'])
                ->setPublic(false));
        }

        $container->setAlias(TenantLimitsInterface::class, $providerId);
    }

    /**
     * Maps the REQ-UI-003 canonical keys (web_ui.enabled, web_ui.path_prefix, web_ui.tenant_paths, security.roles.<action>) and their
     * deprecated 1.x names onto one resolved config: a canonical key wins, else the deprecated key (or its default).
     *
     * @param array<string, mixed> $config processed configuration
     *
     * @return array<string, mixed>
     */
    private function resolveCanonicalKeys(array $config): array
    {
        $config['web_ui']['enabled'] ??= (bool) $config['dashboard']['enabled'];
        $config['web_ui']['path_prefix'] ??= (string) $config['dashboard']['path'];
        $config['dashboard']['enabled'] = $config['web_ui']['enabled'];
        $config['dashboard']['path']    = $config['web_ui']['path_prefix'];
        if ($config['web_ui']['tenant_paths'] === []) {
            $config['web_ui']['tenant_paths'] = $config['dashboard']['tenant_paths'];
        }
        $config['dashboard']['tenant_paths'] = $config['web_ui']['tenant_paths'];

        foreach (['dashboard', 'manage', 'settings'] as $action) {
            $config['security']['roles'][$action] ??= $config['security'][$action . '_roles'];
            $config['security'][$action . '_roles'] = $config['security']['roles'][$action];
        }

        return $config;
    }

    /**
     * Returns the configuration alias (`nowo_uptime_monitor`).
     */
    public function getAlias(): string
    {
        return Configuration::ALIAS;
    }

    /**
     * Applies doctrine.table_prefix to the bundle entity metadata (REQ-ORM-002); no-op while the prefix is empty.
     */
    private function registerTablePrefixListener(ContainerBuilder $container): void
    {
        $container->setDefinition(TablePrefixListener::class, (new Definition(TablePrefixListener::class))
            ->setArgument('$tablePrefix', '%' . Configuration::ALIAS . '.doctrine.table_prefix%')
            ->addTag('doctrine.event_listener', ['event' => 'loadClassMetadata'])
            ->setPublic(false));
    }

    /**
     * Registers the access checker. The built-in role checker denies an area when access_roles and the area roles are
     * both empty, so a web UI whose every role list is empty would refuse everyone: that configuration fails here.
     *
     * @param array<string, mixed> $security
     */
    private function registerAccessChecker(ContainerBuilder $container, array $security, bool $webUiEnabled): void
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
                if (
                    $webUiEnabled
                    && $security['access_roles'] === []
                    && $security['dashboard_roles'] === []
                    && $security['manage_roles'] === []
                    && $security['settings_roles'] === []
                ) {
                    throw new LogicException('NowoUptimeMonitorBundle: security.access_roles and every security.roles.<action> list are empty, so the built-in access checker would deny everyone (empty lists never mean "everyone"). Set at least one role (e.g. access_roles: [ROLE_ADMIN]), set security.access_checker, or disable web_ui.');
                }
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
