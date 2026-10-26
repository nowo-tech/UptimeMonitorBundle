<?php

declare(strict_types=1);

namespace Nowo\UptimeMonitorBundle\Tests\Integration\Contract;

use Nowo\UiKitBundle\Test\PageContractTestCase;
use Nowo\UiKitBundle\Test\PageFixture;
use Nowo\UiKitBundle\View\ListPage;
use Nowo\UptimeMonitorBundle\Ui\UptimePages;
use Twig\Environment;
use Twig\TwigFunction;

use function dirname;
use function substr_count;

/**
 * REQ-UI-003: every bundle page extends a UiKit scaffold (or is a declared unique page on the UiKit layout), fills
 * the canonical blocks, ships no chrome of its own (no <html>, CDN, inline handlers, confirm(), framework classes
 * outside the theme slots), reads the theme slots and renders translated labels only.
 */
final class PageContractTest extends PageContractTestCase
{
    private const VIEWS = '@NowoUptimeMonitorBundle/';

    /** Tenant theme returned by the uptime_theme() stub. */
    private string $theme = 'auto';

    public static function pageFixtures(): iterable
    {
        yield 'tenants' => new PageFixture(self::VIEWS . 'tenant/index.html.twig', PageFixtures::tenantIndex());
        yield 'tenants (empty)' => new PageFixture(self::VIEWS . 'tenant/index.html.twig', PageFixtures::tenantIndex(true));
        yield 'new tenant' => new PageFixture(self::VIEWS . 'tenant/form.html.twig', PageFixtures::tenantForm());
        yield 'dashboard' => new PageFixture(self::VIEWS . 'dashboard/index.html.twig', PageFixtures::dashboard());
        yield 'dashboard (empty)' => new PageFixture(self::VIEWS . 'dashboard/index.html.twig', PageFixtures::dashboard(true));
        yield 'dashboard (filtered, Mercure)' => new PageFixture(self::VIEWS . 'dashboard/index.html.twig', PageFixtures::dashboard(false, true));
        yield 'monitor detail' => new PageFixture(self::VIEWS . 'monitor/show.html.twig', PageFixtures::monitorShow());
        yield 'monitor detail (group)' => new PageFixture(self::VIEWS . 'monitor/show.html.twig', PageFixtures::monitorShow(true));
        yield 'new monitor' => new PageFixture(self::VIEWS . 'monitor/form.html.twig', PageFixtures::monitorForm());
        yield 'edit monitor' => new PageFixture(self::VIEWS . 'monitor/form.html.twig', PageFixtures::monitorForm(true));
        foreach (PageFixtures::settingsPages() as $section => $context) {
            yield 'settings ' . $section => new PageFixture(self::VIEWS . 'settings/' . self::settingsTemplate($section), $context);
        }
        yield 'settings tags (empty)' => new PageFixture(self::VIEWS . 'settings/tags.html.twig', PageFixtures::settingsPages(true)['tags']);
        yield 'status page' => new PageFixture(self::VIEWS . 'status/index.html.twig', PageFixtures::status());
        yield 'status page (no monitors)' => new PageFixture(self::VIEWS . 'status/index.html.twig', PageFixtures::status(true));
    }

    public function testDashboardKeepsTheLiveDashboardContract(): void
    {
        $html = $this->renderPage(new PageFixture(self::VIEWS . 'dashboard/index.html.twig', PageFixtures::dashboard()))->html;

        self::assertStringContainsString('data-nowo-ui-page="dashboard"', $html);
        self::assertStringContainsString('id="uptime-dashboard-root"', $html);
        self::assertStringContainsString('data-api-summary="/main/api/summary"', $html);
        self::assertStringContainsString('data-layout-fragment="/main/fragment/layout"', $html);
        self::assertMatchesRegularExpression('#data-monitor-form-url-template="[^"]*nowo_uptime_monitor_edit[^"]*"#', $html);
        self::assertStringContainsString('data-stat-up>1</span>', $html);
        self::assertStringContainsString('data-sidebar-tree', $html);
        self::assertStringContainsString('data-events-body', $html);
        self::assertStringContainsString('data-monitor-search-form="1"', $html);
        self::assertStringContainsString('<meta name="robots" content="noindex, nofollow">', $html);
        self::assertStringContainsString('/assets/uptime-dashboard.js', $html);
        self::assertStringContainsString('/assets/uptime-dashboard.css', $html);
        // New monitor: a link to the form page that also opens the monitor <dialog>
        self::assertMatchesRegularExpression('#<a [^>]*href="/main/monitors/new"[^>]*data-monitor-form-open#', $html);
        self::assertMatchesRegularExpression('#<dialog id="uptime-monitor-form-modal"[^>]*data-uptime-monitor-form-modal#', $html);
        self::assertSame(1, substr_count($html, 'data-uptime-monitor-form-modal'));
        self::assertStringNotContainsString('data-bs-', $html);
    }

    public function testDashboardFollowsTheTenantTheme(): void
    {
        $html = $this->renderWithTheme('dark', new PageFixture(self::VIEWS . 'dashboard/index.html.twig', PageFixtures::dashboard()));

        self::assertStringContainsString('uptime-app uptime-app--embedded uptime-theme-dark uptime-page uptime-page--dashboard" data-theme="dark"', $html);

        $auto = $this->renderPage(new PageFixture(self::VIEWS . 'dashboard/index.html.twig', PageFixtures::dashboard()))->html;
        self::assertStringContainsString('uptime-theme-auto uptime-page uptime-page--dashboard">', $auto);
    }

    public function testMonitorPagesKeepTheirScriptsAndDialog(): void
    {
        $show = $this->renderPage(new PageFixture(self::VIEWS . 'monitor/show.html.twig', PageFixtures::monitorShow()))->html;
        self::assertStringContainsString('data-nowo-ui-page="show"', $show);
        self::assertStringContainsString('id="uptime-monitor-detail-root"', $show);
        self::assertStringContainsString('data-api-history="/main/api/monitors/7/history"', $show);
        self::assertStringContainsString('id="uptime-chart-response"', $show);
        self::assertStringContainsString('/assets/uptime-monitor-detail.js', $show);
        self::assertStringContainsString('Service unavailable', $show);
        self::assertMatchesRegularExpression('#<a [^>]*href="/main/monitors/7/edit"[^>]*data-monitor-form-open#', $show);
        self::assertStringContainsString('data-uptime-monitor-form-modal', $show);

        $group = $this->renderPage(new PageFixture(self::VIEWS . 'monitor/show.html.twig', PageFixtures::monitorShow(true)))->html;
        self::assertStringContainsString('Monitors in this group', $group);
        self::assertStringContainsString('Database', $group);

        $form = $this->renderPage(new PageFixture(self::VIEWS . 'monitor/form.html.twig', PageFixtures::monitorForm()))->html;
        self::assertStringContainsString('data-nowo-ui-page="form"', $form);
        self::assertStringContainsString('data-monitor-type-select', $form);
        self::assertStringContainsString('data-monitor-fields="http"', $form);
        self::assertStringContainsString('/assets/uptime-dashboard.js', $form);
        self::assertMatchesRegularExpression('#<form [^>]*class="[^"]*uptime-monitor-form#', $form);
    }

    public function testMonitorDialogFragmentIsAFormWithTheUiKitSlots(): void
    {
        $html = $this->createTwig()->render(self::VIEWS . 'monitor/_monitor_form_modal.html.twig', PageFixtures::monitorFormFragment());

        self::assertMatchesRegularExpression('#<form [^>]*data-monitor-form="1"#', $html);
        self::assertStringContainsString('data-nowo-ui-dialog-close', $html);
        self::assertStringContainsString('pc-btn-primary', $html);
        self::assertStringContainsString('data-monitor-type-select', $html);
        self::assertStringNotContainsString('data-bs-dismiss', $html);
    }

    public function testSettingsPagesUseSectionTabsAndConfirmedActions(): void
    {
        $pages = PageFixtures::settingsPages();

        $history = $this->renderPage(new PageFixture(self::VIEWS . 'settings/history.html.twig', $pages['history']))->html;
        self::assertStringContainsString('data-nowo-ui-page="settings"', $history);
        self::assertStringContainsString('data-nowo-ui-region="tabs"', $history);
        self::assertStringContainsString('action="/main/settings/history/purge"', $history);
        self::assertStringContainsString('action="/main/settings/history/clear-stats"', $history);
        self::assertStringContainsString('Delete all checks, aggregates and incidents for this tenant?', $history);
        self::assertStringContainsString('value="token-clear-stats"', $history);
        self::assertStringContainsString('<dialog', $history);

        $tags = $this->renderPage(new PageFixture(self::VIEWS . 'settings/tags.html.twig', $pages['tags']))->html;
        self::assertStringContainsString('Delete the tag &quot;production&quot;? Monitors keep running without it.', $tags);
        self::assertStringContainsString('action="/main/settings/tags/1/delete"', $tags);
        self::assertStringContainsString('fill="#22c55e"', $tags);

        $backup = $this->renderPage(new PageFixture(self::VIEWS . 'settings/backup.html.twig', $pages['backup']))->html;
        self::assertStringContainsString('href="/main/settings/backup/export"', $backup);
        self::assertStringContainsString('enctype="multipart/form-data"', $backup);

        $general = $this->renderPage(new PageFixture(self::VIEWS . 'settings/general.html.twig', $pages['general']))->html;
        self::assertSame(3, substr_count($general, 'class="uptime-settings-group"'));
        self::assertStringContainsString('Regional &amp; time', $general);
    }

    public function testStatusPageIsAGuestPage(): void
    {
        $rendered = $this->renderPage(new PageFixture(self::VIEWS . 'status/index.html.twig', PageFixtures::status()));

        self::assertSame(['guest'], $rendered->layouts());
        self::assertStringContainsString('data-testid="uptime-status-page"', $rendered->html);
        self::assertStringContainsString('Degraded performance', $rendered->html);
        self::assertStringContainsString('API health', $rendered->html);
        self::assertStringContainsString('900 ms', $rendered->html);
        self::assertStringNotContainsString('<script', $rendered->blockHtml('nowo_ui_content'));
    }

    public function testCustomLayoutAndLegacyBridge(): void
    {
        $context                              = PageFixtures::tenantIndex();
        $context['page']                      = new ListPage($context['page']->header, $context['page']->columns, $context['page']->rows, bundle: UptimePages::BUNDLE, layout: UptimePages::LEGACY_LAYOUT_BRIDGE);
        $context['nowo_uptime_legacy_layout'] = 'host/legacy.html.twig';

        $html = $this->createTwig()->render(self::VIEWS . 'tenant/index.html.twig', $context);

        self::assertStringStartsWith('<legacy-layout><title>Tenants</title>', $html);
        self::assertStringContainsString('<host-css/>', $html);
        self::assertStringContainsString('css/nowo-ui.css', $html);
        self::assertStringContainsString('data-nowo-ui-bundle="NowoUptimeMonitorBundle"', $html);
        self::assertStringContainsString('data-nowo-ui-page="list"', $html);
        self::assertStringContainsString('Acme Corp', $html);
    }

    public function testHostOverrideOfADataBlockKeepsTheBundlePage(): void
    {
        $html = $this->renderPage(new PageFixture('host/tenants_override.html.twig', PageFixtures::tenantIndex()))->html;

        self::assertStringContainsString('<p class="host-note">Managed by the host.</p>', $html);
        self::assertStringContainsString('data-nowo-ui-page="list"', $html);
    }

    protected static function inlineTemplates(): array
    {
        return [
            // 1.x host layout (renders uptime_content), wrapped by the deprecated legacy bridge.
            'host/legacy.html.twig' => '<legacy-layout><title>{% block title %}{% endblock %}</title><head-css>{% block stylesheets %}<host-css/>{% endblock %}</head-css>{% block uptime_content %}{% endblock %}<js>{% block javascripts %}{% endblock %}</js></legacy-layout>',
            // web_ui.templates.tenant_index: thin override of a data block.
            'host/tenants_override.html.twig' => "{% extends '@NowoUptimeMonitorBundle/tenant/index.html.twig' %}{% block nowo_ui_list_after %}<p class=\"host-note\">Managed by the host.</p>{% endblock %}",
        ];
    }

    protected function configureTwig(Environment $twig): void
    {
        $twig->addFunction(new TwigFunction('uptime_theme', fn (): string => $this->theme));
        $twig->addFunction(new TwigFunction('uptime_ui_framework', static fn (): string => 'custom'));
    }

    protected static function templatePaths(): array
    {
        return ['NowoUptimeMonitorBundle' => dirname(__DIR__, 3) . '/src/Resources/views'];
    }

    protected static function translationDirs(): array
    {
        return [dirname(__DIR__, 3) . '/src/Resources/translations'];
    }

    protected static function templatesDocument(): string
    {
        return dirname(__DIR__, 3) . '/docs/TEMPLATES.md';
    }

    private function renderWithTheme(string $theme, PageFixture $fixture): string
    {
        $this->theme = $theme;
        try {
            return $this->renderPage($fixture)->html;
        } finally {
            $this->theme = 'auto';
        }
    }

    private static function settingsTemplate(string $section): string
    {
        return $section === 'reverse_proxy' ? 'section.html.twig' : $section . '.html.twig';
    }
}
