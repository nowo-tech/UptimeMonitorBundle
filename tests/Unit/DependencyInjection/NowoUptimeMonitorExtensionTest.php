<?php

declare(strict_types=1);

namespace Nowo\UptimeMonitorBundle\Tests\Unit\DependencyInjection;

use LogicException;
use Nowo\UptimeMonitorBundle\DependencyInjection\Configuration;
use Nowo\UptimeMonitorBundle\DependencyInjection\UptimeMonitorExtension;
use Nowo\UptimeMonitorBundle\Doctrine\TablePrefixListener;
use Nowo\UptimeMonitorBundle\Limits\ConfigurableTenantLimits;
use Nowo\UptimeMonitorBundle\Limits\TenantLimitsInterface;
use Nowo\UptimeMonitorBundle\Security\AllowAllUptimeMonitorAccessChecker;
use Nowo\UptimeMonitorBundle\Security\ConfigurableUptimeMonitorAccessChecker;
use Nowo\UptimeMonitorBundle\Security\UptimeMonitorAccessCheckerInterface;
use Nowo\UptimeMonitorBundle\Tests\Unit\Support\FakeDoctrineExtension;
use Nowo\UptimeMonitorBundle\Tests\Unit\Support\FakeFrameworkExtension;
use Nowo\UptimeMonitorBundle\UptimeMonitorBundle;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\IgnoreDeprecations;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\SecurityBundle\SecurityBundle;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Extension\Extension;

#[CoversClass(UptimeMonitorExtension::class)]
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
            'web_ui'   => ['enabled' => true],
            'security' => ['allow_unauthenticated' => false],
        ]], $container);
    }

    public function testLoadDetectsSecurityBundleFromKernelBundlesWithoutExtension(): void
    {
        $container = new ContainerBuilder();
        $container->setParameter('kernel.bundles', ['SecurityBundle' => SecurityBundle::class]);
        $extension = new UptimeMonitorExtension();
        $extension->load([[
            'web_ui'   => ['enabled' => true],
            'security' => ['allow_unauthenticated' => false],
        ]], $container);

        self::assertSame(
            ConfigurableUptimeMonitorAccessChecker::class,
            $container->getDefinition('nowo_uptime_monitor.access_checker.default')->getClass(),
        );
    }

    public function testLoadThrowsWhenKernelBundlesLacksSecurityBundle(): void
    {
        $container = new ContainerBuilder();
        $container->setParameter('kernel.bundles', []);
        $extension = new UptimeMonitorExtension();

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('requires symfony/security-bundle');

        $extension->load([[
            'web_ui'   => ['enabled' => true],
            'security' => ['allow_unauthenticated' => false],
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
        self::assertSame(
            '/bundles/uptimemonitor',
            $frameworkConfigs[0]['assets']['packages']['nowo_uptime_monitor']['base_path'],
        );

        $doctrineConfigs = $container->getExtensionConfig('doctrine');
        self::assertNotEmpty($doctrineConfigs);
        self::assertArrayHasKey('orm', $doctrineConfigs[0]);
    }

    public function testPrependDoesNotTouchHostLocales(): void
    {
        $container = new ContainerBuilder();
        $container->registerExtension(new FakeFrameworkExtension());

        (new UptimeMonitorExtension())->prepend($container);

        foreach ($container->getExtensionConfig('framework') as $config) {
            self::assertArrayNotHasKey('default_locale', $config);
            self::assertArrayNotHasKey('enabled_locales', $config);
        }
    }

    public function testPrependDoesNotForceHostTranslatorSettings(): void
    {
        $container = new ContainerBuilder();
        $container->registerExtension(new FakeFrameworkExtension());

        (new UptimeMonitorExtension())->prepend($container);

        $configs = $container->getExtensionConfig('framework');
        self::assertNotEmpty($configs);
        foreach ($configs as $config) {
            // Prepending translator.* would enable the translator and override the host's fallbacks.
            self::assertArrayNotHasKey('translator', $config);
        }

        // Catalogue stays reachable through FrameworkBundle's bundle discovery (<bundle path>/Resources/translations).
        self::assertFileExists((new UptimeMonitorBundle())->getPath() . '/Resources/translations/NowoUptimeMonitorBundle.en.yaml');
    }

    public function testDisabledBundleTurnsOffScheduler(): void
    {
        $container = $this->containerWithSecurityBundle();
        (new UptimeMonitorExtension())->load([['enabled' => false, 'scheduler' => ['mode' => 'scheduler', 'tick' => '5 minutes']]], $container);

        self::assertFalse($container->getParameter('nowo_uptime_monitor.enabled'));
        /** @var array<string, mixed> $scheduler */
        $scheduler = $container->getParameter('nowo_uptime_monitor.scheduler');
        self::assertFalse($scheduler['enabled']);
        self::assertSame('5 minutes', $scheduler['tick']);

        $container = $this->containerWithSecurityBundle();
        (new UptimeMonitorExtension())->load([[]], $container);
        /** @var array<string, mixed> $scheduler */
        $scheduler = $container->getParameter('nowo_uptime_monitor.scheduler');
        self::assertTrue($scheduler['enabled']);
    }

    public function testPrependSkipsWhenDoctrineMissing(): void
    {
        $container = new ContainerBuilder();
        (new UptimeMonitorExtension())->prepend($container);

        self::assertFalse($container->hasExtension('doctrine'));
    }

    public function testPrependUiKitDoesNotOverrideHostCssFramework(): void
    {
        $container = new ContainerBuilder();
        $container->registerExtension(new class extends Extension {
            public function load(array $configs, ContainerBuilder $container): void
            {
            }

            public function getAlias(): string
            {
                return 'nowo_ui_kit';
            }
        });
        $container->prependExtensionConfig('nowo_ui_kit', ['css_framework' => 'none']);

        (new UptimeMonitorExtension())->prepend($container);

        $seeded = false;
        foreach ($container->getExtensionConfig('nowo_ui_kit') as $cfg) {
            if (($cfg['css_framework'] ?? null) === 'tabler' || ($cfg['css_framework'] ?? null) === 'bootstrap5') {
                $seeded = true;
            }
        }
        self::assertFalse($seeded);
    }

    public function testPrependSeedsFormKitUptimeMonitorProfileWhenHostUnset(): void
    {
        $container = new ContainerBuilder();
        $container->registerExtension(new class extends Extension {
            public function load(array $configs, ContainerBuilder $container): void
            {
            }

            public function getAlias(): string
            {
                return 'nowo_form_kit';
            }
        });
        $extension = new UptimeMonitorExtension();
        $container->registerExtension($extension);

        $extension->prepend($container);

        $found = false;
        foreach ($container->getExtensionConfig('nowo_form_kit') as $cfg) {
            if (($cfg['css_framework'] ?? null) === 'bootstrap'
                && isset($cfg['profiles']['uptime_monitor']['alias'])
                && $cfg['profiles']['uptime_monitor']['alias'] === 'uptime_monitor'
            ) {
                $found = true;
                self::assertSame('NowoUptimeMonitorBundle', $cfg['profiles']['uptime_monitor']['translation_domain']);
                // REQ-UI-003: no framework classes, convention keys only when translated
                self::assertTrue($cfg['profiles']['uptime_monitor']['convention_requires_translation']);
                self::assertSame(['attr' => [], 'row_attr' => []], $cfg['profiles']['uptime_monitor']['defaults']);
                break;
            }
        }
        self::assertTrue($found);
    }

    public function testPrependDoesNotOverrideExplicitFormKitHostConfig(): void
    {
        $container = new ContainerBuilder();
        $container->registerExtension(new class extends Extension {
            public function load(array $configs, ContainerBuilder $container): void
            {
            }

            public function getAlias(): string
            {
                return 'nowo_form_kit';
            }
        });
        $container->prependExtensionConfig('nowo_form_kit', [
            'css_framework' => 'none',
            'profiles'      => [
                'uptime_monitor' => [
                    'alias'              => 'uptime_monitor',
                    'translation_domain' => 'HostDomain',
                ],
            ],
        ]);
        $extension = new UptimeMonitorExtension();
        $container->registerExtension($extension);

        $extension->prepend($container);

        $bootstrapSeed = false;
        $profileReseed = false;
        foreach ($container->getExtensionConfig('nowo_form_kit') as $cfg) {
            if (($cfg['css_framework'] ?? null) === 'bootstrap') {
                $bootstrapSeed = true;
            }
            if (($cfg['profiles']['uptime_monitor']['translation_domain'] ?? null) === 'NowoUptimeMonitorBundle') {
                $profileReseed = true;
            }
        }
        self::assertFalse($bootstrapSeed);
        self::assertFalse($profileReseed);
    }

    #[IgnoreDeprecations]
    public function testPrependSeedsUiKitFromUiFrameworkWhenHostUnset(): void
    {
        $this->expectUserDeprecationMessageMatches('#"nowo_uptime_monitor\.ui\.framework" option is deprecated#');
        $container = new ContainerBuilder();
        $container->registerExtension(new class extends Extension {
            public function load(array $configs, ContainerBuilder $container): void
            {
            }

            public function getAlias(): string
            {
                return 'nowo_ui_kit';
            }
        });
        $extension = new UptimeMonitorExtension();
        $container->registerExtension($extension);
        $container->prependExtensionConfig('nowo_uptime_monitor', [
            'ui' => ['framework' => 'bootstrap'],
        ]);

        $extension->prepend($container);

        $found = false;
        foreach ($container->getExtensionConfig('nowo_ui_kit') as $cfg) {
            if (($cfg['css_framework'] ?? null) === 'bootstrap5') {
                $found = true;
                break;
            }
        }
        self::assertTrue($found);
    }

    public function testTablePrefixDefaultsToEmptyAndRegistersTheMetadataListener(): void
    {
        $container = $this->containerWithSecurityBundle();
        (new UptimeMonitorExtension())->load([[]], $container);

        self::assertSame('', $container->getParameter('nowo_uptime_monitor.doctrine.table_prefix'));
        self::assertFalse($container->hasParameter('nowo_uptime_monitor.table_prefix'));

        $definition = $container->getDefinition(TablePrefixListener::class);
        self::assertSame('%nowo_uptime_monitor.doctrine.table_prefix%', $definition->getArgument('$tablePrefix'));
        self::assertSame([['event' => 'loadClassMetadata']], $definition->getTag('doctrine.event_listener'));
    }

    public function testTablePrefixIsExposedAsParameter(): void
    {
        $container = $this->containerWithSecurityBundle();
        (new UptimeMonitorExtension())->load([['doctrine' => ['table_prefix' => 'app_']]], $container);

        self::assertSame('app_', $container->getParameter('nowo_uptime_monitor.doctrine.table_prefix'));
    }

    public function testTablePrefixRejectsUnsafeCharacters(): void
    {
        $this->expectException(InvalidConfigurationException::class);

        (new UptimeMonitorExtension())->load([['doctrine' => ['table_prefix' => 'app-; DROP']]], $this->containerWithSecurityBundle());
    }

    public function testCheckLimitsAndStatusPageDefaults(): void
    {
        $container = $this->containerWithSecurityBundle();
        (new UptimeMonitorExtension())->load([[]], $container);

        self::assertSame(60.0, $container->getParameter('nowo_uptime_monitor.checks.max_timeout_seconds'));
        self::assertSame(1_048_576, $container->getParameter('nowo_uptime_monitor.checks.max_body_bytes'));
        self::assertSame(20, $container->getParameter('nowo_uptime_monitor.checks.max_redirects'));
        self::assertSame(300, $container->getParameter('nowo_uptime_monitor.checks.max_scan_seconds'));
        self::assertTrue($container->getParameter('nowo_uptime_monitor.web_ui.enabled'));
        /** @var array<string, mixed> $statusPage */
        $statusPage = $container->getParameter('nowo_uptime_monitor.status_page');
        self::assertTrue($statusPage['default_public']);
    }

    public function testCheckLimitsAreConfigurable(): void
    {
        $container = $this->containerWithSecurityBundle();
        (new UptimeMonitorExtension())->load([[
            'checks'      => ['max_timeout_seconds' => 15.0, 'max_body_bytes' => 4096, 'max_redirects' => 3, 'max_scan_seconds' => 120],
            'status_page' => ['default_public' => false],
        ]], $container);

        self::assertSame(15.0, $container->getParameter('nowo_uptime_monitor.checks.max_timeout_seconds'));
        self::assertSame(4096, $container->getParameter('nowo_uptime_monitor.checks.max_body_bytes'));
        self::assertSame(3, $container->getParameter('nowo_uptime_monitor.checks.max_redirects'));
        self::assertSame(120, $container->getParameter('nowo_uptime_monitor.checks.max_scan_seconds'));
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

    public function testCanonicalWebUiAndRoleKeysResolveTheParameters(): void
    {
        $container = $this->containerWithSecurityBundle();
        (new UptimeMonitorExtension())->load([[
            'web_ui'   => ['path_prefix' => '/projects/{tenantSlug}/uptime', 'layout_template' => 'layouts/admin.html.twig'],
            'security' => ['roles' => ['manage' => ['ROLE_OPS']]],
        ]], $container);

        self::assertSame('/projects/{tenantSlug}/uptime', $container->getParameter('nowo_uptime_monitor.dashboard.path'));
        /** @var array<string, mixed> $webUi */
        $webUi = $container->getParameter('nowo_uptime_monitor.web_ui');
        self::assertTrue($webUi['enabled']);
        self::assertSame('/projects/{tenantSlug}/uptime', $webUi['path_prefix']);
        self::assertSame('layouts/admin.html.twig', $webUi['layout_template']);
        $checker = $container->getDefinition('nowo_uptime_monitor.access_checker.default');
        self::assertSame(['ROLE_OPS'], $checker->getArgument('$manageRoles'));
        self::assertSame(['ROLE_ADMIN'], $checker->getArgument('$dashboardRoles'));
    }

    public function testTenantLimitsDefaultToTheConfiguredValues(): void
    {
        $container = $this->containerWithSecurityBundle();
        (new UptimeMonitorExtension())->load([[
            'limits' => ['max_monitors' => 25, 'min_interval_seconds' => 60],
            'web_ui' => ['dashboard' => ['view' => 'table', 'actions' => ['new_monitor']], 'per_page' => 50],
        ]], $container);

        self::assertSame('nowo_uptime_monitor.tenant_limits.default', (string) $container->getAlias(TenantLimitsInterface::class));
        $limits = $container->getDefinition('nowo_uptime_monitor.tenant_limits.default');
        self::assertSame(ConfigurableTenantLimits::class, $limits->getClass());
        self::assertSame(25, $limits->getArgument('$maxMonitors'));
        self::assertSame(60, $limits->getArgument('$minIntervalSeconds'));
        /** @var array<string, mixed> $webUi */
        $webUi = $container->getParameter('nowo_uptime_monitor.web_ui');
        self::assertEquals(['view' => 'table', 'header' => 'tenant', 'actions' => ['new_monitor']], $webUi['dashboard']);
        self::assertSame(50, $webUi['per_page']);
    }

    public function testTenantLimitsProviderIsAliased(): void
    {
        $container = $this->containerWithSecurityBundle();
        (new UptimeMonitorExtension())->load([['limits' => ['provider' => 'app.uptime_limits']]], $container);

        self::assertSame('app.uptime_limits', (string) $container->getAlias(TenantLimitsInterface::class));
        self::assertFalse($container->hasDefinition('nowo_uptime_monitor.tenant_limits.default'));
        /** @var array<string, mixed> $webUi */
        $webUi = $container->getParameter('nowo_uptime_monitor.web_ui');
        self::assertSame(['view' => 'tree', 'header' => 'tenant', 'actions' => ['settings', 'status_page', 'new_monitor']], $webUi['dashboard']);
        self::assertSame(20, $webUi['per_page']);
    }

    #[IgnoreDeprecations]
    public function testDeprecatedDashboardKeysStillApply(): void
    {
        $this->expectUserDeprecationMessageMatches('#"nowo_uptime_monitor\.dashboard\.path" option is deprecated#');
        $this->expectUserDeprecationMessageMatches('#"nowo_uptime_monitor\.security\.settings_roles" option is deprecated#');
        $container = $this->containerWithSecurityBundle();
        (new UptimeMonitorExtension())->load([[
            'dashboard' => ['path' => '/monitoring'],
            'security'  => ['settings_roles' => ['ROLE_OWNER']],
        ]], $container);

        self::assertSame('/monitoring', $container->getParameter('nowo_uptime_monitor.dashboard.path'));
        self::assertSame(['ROLE_OWNER'], $container->getDefinition('nowo_uptime_monitor.access_checker.default')->getArgument('$settingsRoles'));
    }

    #[IgnoreDeprecations]
    public function testCanonicalTenantPathsWinOverTheDeprecatedKey(): void
    {
        $this->expectUserDeprecationMessageMatches('#"nowo_uptime_monitor\.dashboard\.tenant_paths" option is deprecated#');
        $path      = ['slug_prefix' => 'user-', 'path' => '/me/{tenantSlug}/uptime', 'name_prefix' => 'me_'];
        $container = $this->containerWithSecurityBundle();
        (new UptimeMonitorExtension())->load([['web_ui' => ['tenant_paths' => [$path]]]], $container);

        /** @var list<array<string, mixed>> $tenantPaths */
        $tenantPaths = $container->getParameter('nowo_uptime_monitor.dashboard.tenant_paths');
        self::assertSame('/me/{tenantSlug}/uptime', $tenantPaths[0]['path']);

        $legacy = $this->containerWithSecurityBundle();
        (new UptimeMonitorExtension())->load([['dashboard' => ['tenant_paths' => [$path]]]], $legacy);
        /** @var array<string, mixed> $webUi */
        $webUi = $legacy->getParameter('nowo_uptime_monitor.web_ui');
        self::assertSame('me_', $webUi['tenant_paths'][0]['name_prefix']);
    }

    public function testEveryRoleListEmptyFailsTheCompile(): void
    {
        $empty = ['access_roles' => [], 'roles' => ['dashboard' => [], 'manage' => [], 'settings' => []]];

        try {
            (new UptimeMonitorExtension())->load([['security' => $empty]], $this->containerWithSecurityBundle());
            self::fail('Expected a LogicException for empty role lists.');
        } catch (LogicException $e) {
            self::assertStringContainsString('would deny everyone', $e->getMessage());
        }

        $custom = $this->containerWithSecurityBundle();
        (new UptimeMonitorExtension())->load([['security' => $empty + ['access_checker' => 'app.uptime_access']]], $custom);
        self::assertSame('app.uptime_access', (string) $custom->getAlias(UptimeMonitorAccessCheckerInterface::class));

        $noUi = $this->containerWithSecurityBundle();
        (new UptimeMonitorExtension())->load([['security' => $empty, 'web_ui' => ['enabled' => false]]], $noUi);
        self::assertTrue($noUi->hasDefinition('nowo_uptime_monitor.access_checker.default'));

        $oneArea = $this->containerWithSecurityBundle();
        (new UptimeMonitorExtension())->load([['security' => ['access_roles' => [], 'roles' => ['dashboard' => [], 'manage' => ['ROLE_OPS'], 'settings' => []]]]], $oneArea);
        self::assertSame([], $oneArea->getDefinition('nowo_uptime_monitor.access_checker.default')->getArgument('$accessRoles'));
    }
}
