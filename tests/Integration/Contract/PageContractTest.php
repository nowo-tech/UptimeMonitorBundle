<?php

declare(strict_types=1);

namespace Nowo\UptimeMonitorBundle\Tests\Integration\Contract;

use LogicException;
use Nowo\UiKitBundle\Test\PageContractTestCase;
use Nowo\UiKitBundle\Test\PageFixture;
use Nowo\UiKitBundle\Test\RenderedPage;
use Nowo\UiKitBundle\View\ListPage;
use Nowo\UptimeMonitorBundle\Ui\UptimeAreaNav;
use Nowo\UptimeMonitorBundle\Ui\UptimePages;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\IgnoreDeprecations;
use Twig\Environment;
use Twig\TwigFunction;

use function array_map;
use function dirname;
use function is_string;
use function str_starts_with;
use function strpos;
use function substr;
use function substr_count;

use const ENT_HTML5;
use const ENT_QUOTES;
use const JSON_THROW_ON_ERROR;

/**
 * REQ-UI-003: every bundle page extends a UiKit scaffold (or is a declared unique page on the UiKit layout), fills
 * the canonical blocks, ships no chrome of its own (no <html>, CDN, inline handlers, confirm(), framework classes
 * outside the theme slots), reads the theme slots and renders translated labels only.
 */
final class PageContractTest extends PageContractTestCase
{
    private const VIEWS = '@NowoUptimeMonitorBundle/';

    private const FIXTURE_KEY = 'uptime_fixture';

    /** Tenant theme returned by the uptime_theme() stub. */
    private string $theme = 'auto';

    /**
     * Fixtures carry only a key (`uptime_fixture`): the contexts hold FormViews with closures, which PHPUnit cannot
     * serialize under --process-isolation; {@see self::renderPage()} builds the context in the test process.
     */
    public static function pageFixtures(): iterable
    {
        foreach (self::fixtureTemplates() as $name => $template) {
            yield $name => new PageFixture($template, [self::FIXTURE_KEY => $name]);
        }
    }

    protected function renderPage(PageFixture $fixture, string $framework = 'none'): RenderedPage
    {
        return parent::renderPage($this->resolve($fixture), $framework);
    }

    /**
     * REQ-SF-005: UiKit's inheritance walk (PageContractTestCase::inheritanceChain(), ui-kit-bundle 2.15.4) calls
     * Twig APIs deprecated by twig/twig (Environment::mergeGlobals(), TemplateWrapper::unwrap() without the
     * environment). Those third-party deprecations are ignored for this test only (a PHPUnit baseline is not applied
     * under --process-isolation); every other test still fails on any deprecation. Drop the override once UiKit no
     * longer calls them: https://github.com/nowo-tech/UptimeMonitorBundle/issues/123.
     */
    #[DataProvider('contractPages')]
    #[IgnoreDeprecations]
    public function testPageExtendsAScaffoldOrIsDeclaredUnique(PageFixture $fixture): void
    {
        parent::testPageExtendsAScaffoldOrIsDeclaredUnique($fixture);
    }

    protected function assertExtendsScaffoldOrUnique(PageFixture $fixture): void
    {
        parent::assertExtendsScaffoldOrUnique($this->resolve($fixture));
    }

    /**
     * @return array<string, string> fixture name => template
     */
    private static function fixtureTemplates(): array
    {
        $templates = [
            'tenants'                       => 'tenant/index.html.twig',
            'tenants (empty)'               => 'tenant/index.html.twig',
            'new tenant'                    => 'tenant/form.html.twig',
            'dashboard'                     => 'dashboard/index.html.twig',
            'dashboard (empty)'             => 'dashboard/index.html.twig',
            'dashboard (filtered, Mercure)' => 'dashboard/index.html.twig',
            'monitor detail'                => 'monitor/show.html.twig',
            'monitor detail (group)'        => 'monitor/show.html.twig',
            'monitor detail (log pages)'    => 'monitor/show.html.twig',
            'dashboard (table)'             => 'dashboard/index.html.twig',
            'dashboard (table, empty)'      => 'dashboard/index.html.twig',
            'new monitor'                   => 'monitor/form.html.twig',
            'edit monitor'                  => 'monitor/form.html.twig',
        ];
        foreach (UptimeAreaNav::SETTINGS_SECTIONS as $section => $route) {
            $templates['settings ' . $section] = 'settings/' . self::settingsTemplate($section);
        }
        $templates['settings tags (empty)']     = 'settings/tags.html.twig';
        $templates['status page']               = 'status/index.html.twig';
        $templates['status page (no monitors)'] = 'status/index.html.twig';
        $templates['settings tags (editing)']   = 'settings/tags.html.twig';
        $templates['summary']                   = 'summary/index.html.twig';
        $templates['summary (no monitors)']     = 'summary/index.html.twig';

        return array_map(static fn (string $t): string => self::VIEWS . $t, $templates);
    }

    /**
     * @return array<string, mixed>
     */
    private function context(string $name): array
    {
        if (str_starts_with($name, 'settings ')) {
            return match ($name) {
                'settings tags (empty)'   => PageFixtures::settingsPages(true)['tags'],
                'settings tags (editing)' => PageFixtures::settingsPages(false, true)['tags'],
                default                   => PageFixtures::settingsPages()[substr($name, 9)],
            };
        }

        return match ($name) {
            'tenants'                       => PageFixtures::tenantIndex(),
            'tenants (empty)'               => PageFixtures::tenantIndex(true),
            'new tenant'                    => PageFixtures::tenantForm(),
            'dashboard'                     => PageFixtures::dashboard(),
            'dashboard (empty)'             => PageFixtures::dashboard(true),
            'dashboard (filtered, Mercure)' => PageFixtures::dashboard(false, true),
            'monitor detail'                => PageFixtures::monitorShow(),
            'monitor detail (group)'        => PageFixtures::monitorShow(true),
            'monitor detail (log pages)'    => PageFixtures::monitorShowPaged(),
            'dashboard (table)'             => PageFixtures::dashboardTable(),
            'dashboard (table, empty)'      => PageFixtures::dashboardTable(true),
            'new monitor'                   => PageFixtures::monitorForm(),
            'edit monitor'                  => PageFixtures::monitorForm(true),
            'status page'                   => PageFixtures::status(),
            'status page (no monitors)'     => PageFixtures::status(true),
            'summary'                       => PageFixtures::summary(),
            'summary (no monitors)'         => PageFixtures::summary(true),
            default                         => throw new LogicException('Unknown page fixture ' . $name),
        };
    }

    private function resolve(PageFixture $fixture): PageFixture
    {
        $name = $fixture->context[self::FIXTURE_KEY] ?? null;

        return is_string($name) ? new PageFixture($fixture->template, $this->context($name)) : $fixture;
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

        // 1.17: the translated status badge sits in the details card header; no card wraps the page cards and the check
        // log scrolls in a plain wrapper (not the table_wrap slot inside the log card).
        self::assertMatchesRegularExpression('#<header class="[^"]*uptime-details-header">\s*<h2[^>]*>Details</h2>\s*<p class="uptime-details-status">#', $show);
        self::assertMatchesRegularExpression('#<span class="uptime-status-badge uptime-status-badge--down nowo-ui-badge nowo-ui-badge--danger[^"]*" data-detail-status[^>]*>Down</span>#', $show);
        self::assertSame(1, preg_match('#data-status-badges="([^"]+)"#', $show, $badges));
        $badges = json_decode(html_entity_decode($badges[1], ENT_QUOTES | ENT_HTML5), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame(['up', 'down', 'degraded', 'unknown'], array_keys($badges));
        self::assertSame('Up', $badges['up']['label']);
        self::assertStringContainsString('nowo-ui-badge--warning', $badges['degraded']['class']);
        self::assertStringNotContainsString('uptime-detail-header', $show);
        self::assertStringContainsString('<div class="uptime-table-scroll">', $show);
        self::assertStringNotContainsString('nowo-ui-table-wrap', $show);

        $push = $this->renderPage(new PageFixture(self::VIEWS . 'monitor/show.html.twig', PageFixtures::monitorShowPush()))->html;
        self::assertStringContainsString('data-testid="uptime-push-health"', $push);
        self::assertStringContainsString('data-testid="uptime-push-disks"', $push);
        self::assertSame(3, substr_count($push, '<div class="uptime-chart-box">'));
        self::assertStringNotContainsString('nowo-ui-table-wrap', $push);
        self::assertStringNotContainsString('id="uptime-chart-response"', $push);

        $group = $this->renderPage(new PageFixture(self::VIEWS . 'monitor/show.html.twig', PageFixtures::monitorShow(true)))->html;
        self::assertStringContainsString('Monitors in this group', $group);
        self::assertMatchesRegularExpression('#nowo-ui-badge--neutral[^"]*" data-detail-status[^>]*>Unknown</span>#', $group);
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

    public function testTableDashboardAndPagedMonitorPage(): void
    {
        $table = $this->renderPage(new PageFixture(self::VIEWS . 'dashboard/index.html.twig', PageFixtures::dashboardTable()))->html;
        self::assertStringContainsString('data-uptime-dashboard-table', $table);
        self::assertStringNotContainsString('id="uptime-dashboard-root"', $table);
        self::assertStringContainsString('data-testid="uptime-count-up"', $table);
        self::assertStringContainsString('data-responsive-table-target="table"', $table);
        self::assertStringContainsString('href="/main/monitors/7"', $table);
        self::assertStringContainsString('Delete the monitor “API health” and its check history?', $table);
        self::assertStringContainsString('data-testid="uptime-events-pagination"', $table);
        self::assertStringContainsString('<p class="host-section">Shared with Ana</p>', $table);
        self::assertStringContainsString('data-uptime-monitor-form-modal', $table);

        $empty = $this->renderPage(new PageFixture(self::VIEWS . 'dashboard/index.html.twig', PageFixtures::dashboardTable(true)))->html;
        self::assertStringContainsString('data-testid="uptime-monitors-empty"', $empty);
        self::assertStringContainsString('No monitors yet', $empty);
        self::assertStringNotContainsString('data-testid="uptime-events"', $empty);

        $show = $this->renderPage(new PageFixture(self::VIEWS . 'monitor/show.html.twig', PageFixtures::monitorShowPaged()))->html;
        self::assertStringContainsString('data-testid="uptime-monitor-log-pagination"', $show);
        self::assertLessThan(strpos($show, 'id="uptime-monitor-detail-root"'), strpos($show, 'Shared with Ana'));
        self::assertStringNotContainsString('data-detail-unchecked', $show);

        $unchecked = $this->renderPage(new PageFixture(self::VIEWS . 'monitor/show.html.twig', PageFixtures::monitorShow(true)))->html;
        self::assertStringContainsString('data-detail-unchecked', $unchecked);
        self::assertMatchesRegularExpression('#data-detail-pending\s+hidden#', $unchecked);
    }

    public function testSummaryAndTagEdit(): void
    {
        $summary = $this->renderPage(new PageFixture(self::VIEWS . 'summary/index.html.twig', PageFixtures::summary()))->html;
        self::assertStringContainsString('data-nowo-ui-page="dashboard"', $summary);
        self::assertStringContainsString('0 of 2 monitors operational', $summary);
        self::assertStringContainsString('data-testid="uptime-summary-count-down"', $summary);
        self::assertStringContainsString('href="https://example.test/status/main"', $summary);
        self::assertStringContainsString('Not checked yet', $summary);

        $empty = $this->renderPage(new PageFixture(self::VIEWS . 'summary/index.html.twig', PageFixtures::summary(true)))->html;
        self::assertStringContainsString('data-testid="uptime-summary-empty"', $empty);
        self::assertStringContainsString('Go to monitors', $empty);

        $tags = $this->renderPage(new PageFixture(self::VIEWS . 'settings/tags.html.twig', PageFixtures::settingsPages(false, true)['tags']))->html;
        self::assertStringContainsString('href="/main/settings/tags/1"', $tags);
        self::assertStringContainsString('Edit the tag “production”', $tags);
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
            // UptimePageEvent section of a host.
            'host/_section.html.twig'         => '<p class="host-section">{{ note }}</p>',
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
