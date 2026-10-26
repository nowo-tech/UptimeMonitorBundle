<?php

declare(strict_types=1);

namespace Nowo\UptimeMonitorBundle\Tests\Unit\Ui;

use Nowo\UptimeMonitorBundle\DependencyInjection\Configuration;
use Nowo\UptimeMonitorBundle\Ui\UptimePages;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\IgnoreDeprecations;
use PHPUnit\Framework\TestCase;
use Twig\Environment;
use Twig\Loader\ArrayLoader;

#[CoversClass(UptimePages::class)]
final class UptimePagesTest extends TestCase
{
    public function testDefaultsUseTheUiKitLayoutAndFormTheme(): void
    {
        $pages = new UptimePages(['layout_template' => null, 'templates' => [], 'form_themes' => [Configuration::DEFAULT_FORM_THEME]], ['layout' => Configuration::LEGACY_LAYOUT], new Environment(new ArrayLoader()));

        self::assertNull($pages->layout());
        self::assertNull($pages->legacyLayout());
        self::assertSame([Configuration::DEFAULT_FORM_THEME], $pages->formThemes());
        self::assertSame('@NowoUptimeMonitorBundle/dashboard/index.html.twig', $pages->template('dashboard', '@NowoUptimeMonitorBundle/dashboard/index.html.twig'));
        self::assertSame(['page' => 1, 'nowo_uptime_legacy_layout' => null, 'uptime_form_themes' => [Configuration::DEFAULT_FORM_THEME]], $pages->context(['page' => 1]));
    }

    public function testWithoutConfigurationOrTwig(): void
    {
        $pages = new UptimePages();

        self::assertNull($pages->layout());
        self::assertSame([Configuration::DEFAULT_FORM_THEME], $pages->formThemes());
    }

    public function testWebUiLayoutAndPageOverride(): void
    {
        $pages = new UptimePages(['layout_template' => 'layouts/admin.html.twig', 'templates' => ['dashboard' => 'uptime/dashboard.html.twig', 'status' => ''], 'form_themes' => ['a.html.twig', '', 3]], ['layout' => 'base.html.twig']);

        self::assertSame('layouts/admin.html.twig', $pages->layout());
        self::assertNull($pages->legacyLayout(), 'web_ui.layout_template wins over the deprecated templates.layout');
        self::assertSame('uptime/dashboard.html.twig', $pages->template('dashboard', 'default.html.twig'));
        self::assertSame('default.html.twig', $pages->template('status', 'default.html.twig'));
        self::assertSame(['a.html.twig'], $pages->formThemes());
    }

    #[IgnoreDeprecations]
    public function testDeprecatedTemplatesLayoutIsBridged(): void
    {
        $this->expectUserDeprecationMessageMatches('#wrapped in "@NowoUptimeMonitorBundle/_legacy_layout\.html\.twig"#');
        $pages = new UptimePages(['layout_template' => null], ['layout' => 'base_uptime.html.twig']);

        self::assertSame(UptimePages::LEGACY_LAYOUT_BRIDGE, $pages->layout());
        self::assertSame('base_uptime.html.twig', $pages->context([])['nowo_uptime_legacy_layout']);
    }

    #[IgnoreDeprecations]
    public function testApplicationOverrideOfTheFormerDefaultLayoutIsBridged(): void
    {
        $twig  = new Environment(new ArrayLoader([Configuration::LEGACY_LAYOUT => '{% block uptime_content %}{% endblock %}']));
        $pages = new UptimePages([], ['layout' => Configuration::LEGACY_LAYOUT], $twig);

        self::assertSame(UptimePages::LEGACY_LAYOUT_BRIDGE, $pages->layout());
        self::assertSame(Configuration::LEGACY_LAYOUT, $pages->legacyLayout());
    }

    public function testInvalidValuesFallBack(): void
    {
        $pages = new UptimePages(['form_themes' => 'not-a-list'], ['layout' => '']);

        self::assertNull($pages->layout());
        self::assertSame([], $pages->formThemes());
    }

    public function testLabelUsesTheBundleDomain(): void
    {
        $label = UptimePages::label('dashboard.title', ['%name%' => 'Main']);

        self::assertSame('dashboard.title', $label->key);
        self::assertSame('NowoUptimeMonitorBundle', $label->domain);
        self::assertSame(['%name%' => 'Main'], $label->params);
    }
}
