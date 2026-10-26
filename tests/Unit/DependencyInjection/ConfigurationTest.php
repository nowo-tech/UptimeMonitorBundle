<?php

declare(strict_types=1);

namespace Nowo\UptimeMonitorBundle\Tests\Unit\DependencyInjection;

use Nowo\UptimeMonitorBundle\DependencyInjection\Configuration;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Config\Definition\ArrayNode;
use Symfony\Component\Config\Definition\BaseNode;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;
use Symfony\Component\Config\Definition\Processor;

/**
 * @covers \Nowo\UptimeMonitorBundle\DependencyInjection\Configuration
 */
final class ConfigurationTest extends TestCase
{
    public function testConfigTreeBuilderUsesAliasAsRoot(): void
    {
        $treeBuilder = (new Configuration())->getConfigTreeBuilder();
        $root        = $treeBuilder->buildTree();

        self::assertSame(Configuration::ALIAS, $root->getName());
    }

    public function testDefaults(): void
    {
        $config = (new Processor())->processConfiguration(new Configuration(), []);

        self::assertTrue($config['enabled']);
        self::assertSame('default', $config['connection']);
        self::assertSame(30, $config['retention']['detail_days']);
        self::assertSame(1000, $config['retention']['purge_batch_size']);
        self::assertTrue($config['aggregates']['keep_forever']);
        self::assertSame('polling', $config['dashboard']['sync']);
        self::assertSame('scheduler', $config['scheduler']['mode']);
        self::assertSame(0, $config['checks']['min_latency_ms']);
        self::assertSame('@NowoUptimeMonitorBundle/layout.html.twig', $config['templates']['layout']);
        self::assertSame('tabler', $config['ui']['framework']);
        self::assertFalse($config['ui']['tabler']['skip_cdn']);
        self::assertTrue($config['tenants']['list_enabled']);
        self::assertFalse($config['tenants']['redirect_when_single']);
        self::assertSame(['ROLE_ADMIN'], $config['security']['access_roles']);
        self::assertFalse($config['security']['allow_unauthenticated']);
        self::assertNull($config['security']['access_checker']);
        self::assertSame(['ROLE_ADMIN'], $config['security']['dashboard_roles']);
        self::assertSame(['ROLE_ADMIN'], $config['security']['manage_roles']);
        self::assertSame(['ROLE_ADMIN'], $config['security']['settings_roles']);
    }

    public function testLayoutTemplateAliasMapsToLayout(): void
    {
        $config = (new Processor())->processConfiguration(new Configuration(), [[
            'templates' => [
                'layout_template' => 'base.html.twig',
            ],
        ]]);

        self::assertSame('base.html.twig', $config['templates']['layout']);
        self::assertArrayNotHasKey('layout_template', $config['templates']);
    }

    public function testCssFrameworkAliasMapsToFramework(): void
    {
        $config = (new Processor())->processConfiguration(new Configuration(), [[
            'ui' => [
                'css_framework' => 'bootstrap',
            ],
        ]]);

        self::assertSame('bootstrap', $config['ui']['framework']);
        self::assertArrayNotHasKey('css_framework', $config['ui']);
    }

    public function testCanonicalKeysWinOverAliases(): void
    {
        $config = (new Processor())->processConfiguration(new Configuration(), [[
            'templates' => [
                'layout'          => 'canonical-layout.html.twig',
                'layout_template' => 'ignored-alias.html.twig',
            ],
            'ui' => [
                'framework'     => 'tailwind',
                'css_framework' => 'bootstrap',
            ],
        ]]);

        self::assertSame('canonical-layout.html.twig', $config['templates']['layout']);
        self::assertSame('tailwind', $config['ui']['framework']);
    }

    /**
     * @return iterable<string, array{list<string>}>
     */
    public static function deadKeyProvider(): iterable
    {
        yield 'environments' => [['environments']];
        yield 'table_prefix' => [['table_prefix']];
        yield 'aggregates.keep_forever' => [['aggregates', 'keep_forever']];
        yield 'multi_tenant.enabled' => [['multi_tenant', 'enabled']];
        yield 'multi_tenant.header' => [['multi_tenant', 'header']];
        yield 'dashboard.prefix' => [['dashboard', 'prefix']];
        yield 'dashboard.roles' => [['dashboard', 'roles']];
    }

    /**
     * @param list<string> $path
     */
    #[DataProvider('deadKeyProvider')]
    public function testUnusedKeysAreDeprecated(array $path): void
    {
        $node = (new Configuration())->getConfigTreeBuilder()->buildTree();
        foreach ($path as $segment) {
            self::assertInstanceOf(ArrayNode::class, $node);
            $node = $node->getChildren()[$segment];
        }

        self::assertInstanceOf(BaseNode::class, $node);
        self::assertTrue($node->isDeprecated());
        $deprecation = $node->getDeprecation($node->getName(), 'nowo_uptime_monitor');
        self::assertSame('nowo-tech/uptime-monitor-bundle', $deprecation['package']);
        self::assertSame('1.5', $deprecation['version']);
        self::assertStringContainsString('is deprecated and has no effect', $deprecation['message']);
    }

    public function testLiveKeysAreNotDeprecated(): void
    {
        $root = (new Configuration())->getConfigTreeBuilder()->buildTree();
        self::assertInstanceOf(ArrayNode::class, $root);
        $enabled = $root->getChildren()['enabled'];
        self::assertInstanceOf(BaseNode::class, $enabled);
        self::assertFalse($enabled->isDeprecated());
    }

    public function testWebUiDefaults(): void
    {
        $config = (new Processor())->processConfiguration(new Configuration(), []);

        self::assertNull($config['web_ui']['enabled']);
        self::assertNull($config['web_ui']['path_prefix']);
        self::assertNull($config['web_ui']['layout_template']);
        self::assertSame([], $config['web_ui']['templates']);
        self::assertSame([Configuration::DEFAULT_FORM_THEME], $config['web_ui']['form_themes']);
        self::assertSame(['dashboard' => null, 'manage' => null, 'settings' => null], $config['security']['roles']);
    }

    public function testWebUiAcceptsPageOverridesAndRoles(): void
    {
        $config = (new Processor())->processConfiguration(new Configuration(), [[
            'web_ui'   => ['layout_template' => 'layouts/admin.html.twig', 'templates' => ['dashboard' => 'uptime/dashboard.html.twig'], 'form_themes' => []],
            'security' => ['roles' => ['manage' => ['ROLE_OPS']]],
        ]]);

        self::assertSame('layouts/admin.html.twig', $config['web_ui']['layout_template']);
        self::assertSame(['dashboard' => 'uptime/dashboard.html.twig'], $config['web_ui']['templates']);
        self::assertSame([], $config['web_ui']['form_themes']);
        self::assertSame(['ROLE_OPS'], $config['security']['roles']['manage']);
    }

    public function testWebUiRejectsUnknownPages(): void
    {
        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage('Unknown nowo_uptime_monitor.web_ui.templates page(s) "nope"');

        (new Processor())->processConfiguration(new Configuration(), [['web_ui' => ['templates' => ['nope' => 'x.html.twig']]]]);
    }

    public function testRolesMustBeAListOfRoleNames(): void
    {
        $this->expectException(InvalidConfigurationException::class);

        (new Processor())->processConfiguration(new Configuration(), [['security' => ['roles' => ['dashboard' => 'ROLE_ADMIN']]]]);
    }

    /**
     * @return iterable<string, array{0: list<string>}>
     */
    public static function uiContractDeprecatedKeyProvider(): iterable
    {
        yield 'templates.layout' => [['templates', 'layout']];
        yield 'ui.framework' => [['ui', 'framework']];
        yield 'ui.tabler.skip_cdn' => [['ui', 'tabler', 'skip_cdn']];
        yield 'ui.bootstrap.css_url' => [['ui', 'bootstrap', 'css_url']];
        yield 'ui.tailwind.cdn_script' => [['ui', 'tailwind', 'cdn_script']];
        yield 'dashboard.enabled' => [['dashboard', 'enabled']];
        yield 'dashboard.path' => [['dashboard', 'path']];
        yield 'security.dashboard_roles' => [['security', 'dashboard_roles']];
        yield 'security.manage_roles' => [['security', 'manage_roles']];
        yield 'security.settings_roles' => [['security', 'settings_roles']];
    }

    /**
     * REQ-UI-003 configuration vocabulary: the 1.x names stay for one minor version, deprecated.
     *
     * @param list<string> $path
     */
    #[DataProvider('uiContractDeprecatedKeyProvider')]
    public function testUiContractReplacedKeysAreDeprecated(array $path): void
    {
        $node = (new Configuration())->getConfigTreeBuilder()->buildTree();
        foreach ($path as $segment) {
            self::assertInstanceOf(ArrayNode::class, $node);
            $node = $node->getChildren()[$segment];
        }

        self::assertInstanceOf(BaseNode::class, $node);
        self::assertTrue($node->isDeprecated());
        self::assertSame('1.10', $node->getDeprecation($node->getName(), 'nowo_uptime_monitor')['version']);
    }
}
