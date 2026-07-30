<?php

declare(strict_types=1);

namespace Nowo\UptimeMonitorBundle\Tests\Unit\DependencyInjection;

use Nowo\UptimeMonitorBundle\DependencyInjection\Configuration;
use PHPUnit\Framework\TestCase;
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
        self::assertTrue($config['aggregates']['keep_forever']);
        self::assertSame('polling', $config['dashboard']['sync']);
        self::assertSame('scheduler', $config['scheduler']['mode']);
        self::assertSame(0, $config['checks']['min_latency_ms']);
        self::assertSame('@NowoUptimeMonitorBundle/layout.html.twig', $config['templates']['layout']);
        self::assertSame('tabler', $config['ui']['framework']);
        self::assertFalse($config['ui']['tabler']['skip_cdn']);
        self::assertTrue($config['tenants']['list_enabled']);
        self::assertFalse($config['tenants']['redirect_when_single']);
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
}
