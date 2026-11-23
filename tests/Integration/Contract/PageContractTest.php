<?php

declare(strict_types=1);

namespace Nowo\UptimeMonitorBundle\Tests\Integration\Contract;

use LogicException;
use Nowo\UiKitBundle\Test\PageContractTestCase;
use Nowo\UiKitBundle\Test\PageFixture;
use Nowo\UiKitBundle\Test\RenderedPage;
use Nowo\UiKitBundle\Test\StubHost;
use Nowo\UiKitBundle\View\Action;
use Nowo\UiKitBundle\View\ListPage;
use Nowo\UiKitBundle\View\PageHeader;
use Nowo\UiKitBundle\View\ShowPage;
use Nowo\UptimeMonitorBundle\DependencyInjection\Configuration;
use Nowo\UptimeMonitorBundle\Entity\Monitor;
use Nowo\UptimeMonitorBundle\Enum\MonitorType;
use Nowo\UptimeMonitorBundle\Form\Model\MonitorFormData;
use Nowo\UptimeMonitorBundle\Form\MonitorFormType;
use Nowo\UptimeMonitorBundle\Ui\MonitorLogSort;
use Nowo\UptimeMonitorBundle\Ui\MonitorShowLayout;
use Nowo\UptimeMonitorBundle\Ui\NewMonitorState;
use Nowo\UptimeMonitorBundle\Ui\UptimeAreaNav;
use Nowo\UptimeMonitorBundle\Ui\UptimePages;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\IgnoreDeprecations;
use Symfony\Component\Config\Definition\Processor;
use Symfony\Component\Form\FormError;
use Twig\Environment;
use Twig\TwigFunction;

use function array_keys;
use function array_map;
use function dirname;
use function file_get_contents;
use function fwrite;
use function getenv;
use function html_entity_decode;
use function is_string;
use function json_decode;
use function preg_match;
use function preg_match_all;
use function sprintf;
use function str_starts_with;
use function strlen;
use function strpos;
use function substr;
use function substr_count;

use const ENT_HTML5;
use const ENT_QUOTES;
use const JSON_THROW_ON_ERROR;
use const STDERR;

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
        // 1.19 (UiKit 2.29): the wide dialog size and an empty footer target the dialog script fills with the actions.
        self::assertMatchesRegularExpression('#<dialog id="uptime-monitor-form-modal"[^>]*class="[^"]*nowo-ui-dialog--wide#', $html);
        self::assertMatchesRegularExpression('#<dialog id="uptime-monitor-form-modal".*?<footer [^>]*data-nowo-ui-dialog-footer hidden[^>]*></footer>#s', $html);
        // Dialog rule v2: the idle footer (Cancel, secondary) for the steps without the form's actions; no primary in it.
        self::assertMatchesRegularExpression('#<dialog id="uptime-monitor-form-modal".*?<template data-monitor-form-footer-idle><button type="button" class="[^"]*nowo-ui-btn--secondary[^"]*" data-nowo-ui-dialog-close>Cancel</button></template>.*?</dialog>#s', $html);
        self::assertSame(1, preg_match('#<dialog id="uptime-monitor-form-modal".*?</dialog>#s', $html, $dialog));
        self::assertStringNotContainsString('nowo-ui-btn--primary', $dialog[0]);
        self::assertStringNotContainsString('data-bs-', $html);
    }

    public function testTheDialogCarriesNewMonitorForHostOpenersWhileItIsAllowed(): void
    {
        $plain = $this->renderPage(new PageFixture(self::VIEWS . 'dashboard/index.html.twig', PageFixtures::dashboard()))->html;
        self::assertStringNotContainsString('data-uptime-new-monitor-url', $plain, 'no state, no URL (1.21 markup)');

        $open = $this->renderPage(new PageFixture(self::VIEWS . 'dashboard/index.html.twig', ['uptime_new_monitor' => new NewMonitorState('/main/monitors/new?a=1&b="2"', UptimePages::label('monitor.new'))] + PageFixtures::dashboard()))->html;
        self::assertMatchesRegularExpression('#<dialog id="uptime-monitor-form-modal"[^>]*data-uptime-new-monitor-url="/main/monitors/new\?a=1&amp;b=&quot;2&quot;"[^>]*data-uptime-new-monitor-title="New monitor"#', $open);

        $full = $this->renderPage(new PageFixture(self::VIEWS . 'dashboard/index.html.twig', ['uptime_new_monitor' => new NewMonitorState('/main/monitors/new', UptimePages::label('monitor.new'), UptimePages::label('monitor.limits.max_monitors', ['%count%' => 2]))] + PageFixtures::dashboard()))->html;
        self::assertStringNotContainsString('data-uptime-new-monitor-url', $full, 'at the limit the host opener does nothing');
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

        // 1.18 form UX: intros, unit suffixes, switches, required markers, the folded Advanced block, split rows.
        self::assertStringContainsString('class="uptime-form-section__intro"', $form);
        self::assertMatchesRegularExpression('#<span class="uptime-input-group__suffix" id="monitor_form_intervalSeconds_unit" aria-hidden="true">s</span>#', $form);
        self::assertStringNotContainsString('form.monitor.unit.seconds', $form, 'the unit suffix is translated');
        self::assertMatchesRegularExpression('#aria-describedby="monitor_form_intervalSeconds_unit monitor_form_intervalSeconds_help"#', $form);
        self::assertMatchesRegularExpression('#<input type="checkbox"[^>]*role="switch"[^>]*name="monitor_form\[paused\]"|name="monitor_form\[paused\]"[^>]*role="switch"#', $form);
        self::assertStringContainsString('uptime-label-required', $form);
        self::assertMatchesRegularExpression('#<details class="uptime-form-section uptime-form-advanced" data-monitor-fields="http-advanced">#', $form, 'folded while every advanced field has its default');
        // 1.38 (UX-018, no card in a card): every section is flat, a heading (legend / summary) with no card slot on the box.
        self::assertMatchesRegularExpression('#<fieldset class="uptime-form-section">\s*<legend class="uptime-form-section__title">General</legend>#', $form);
        self::assertMatchesRegularExpression('#<summary class="uptime-form-advanced__summary uptime-form-section__title">#', $form);
        self::assertSame(substr_count($form, '<fieldset class="uptime-form-section'), preg_match_all('#<fieldset class="uptime-form-section[^"]*"[^>]*>\s*<legend class="uptime-form-section__title">#', $form));
        self::assertDoesNotMatchRegularExpression('#<(fieldset|details) class="uptime-form-section[^"]*nowo-ui-card#', $form, 'no card inside the form page card');
        self::assertStringNotContainsString('<legend>', $form);
        self::assertStringContainsString('data-monitor-fields="check-timeout"', $form);
        self::assertStringContainsString('data-monitor-fields="tcp-port"', $form);
        self::assertStringContainsString('data-required-for="http https"', $form);
        self::assertStringContainsString('placeholder="https://example.com/health"', $form);
        self::assertStringNotContainsString('(seconds)', $form);
        self::assertStringContainsString('Uptime Kuma', $form, 'the timeout help explains the 48 s default');

        $data          = new MonitorFormData();
        $data->keyword = 'OK';
        $view          = PageFixtures::forms()->create(MonitorFormType::class, $data)->createView();
        $fragment      = $this->createTwig()->render(self::VIEWS . 'monitor/_monitor_form_modal.html.twig', ['form' => $view] + PageFixtures::monitorFormFragment());
        self::assertStringContainsString('data-monitor-fields="http-advanced" open', $fragment, 'a non-default advanced value opens the block');
        // 1.19: the actions row moves into the dialog footer; its buttons stay bound to the form (Cancel, Save last).
        self::assertStringContainsString('<form name="monitor_form" method="post" id="uptime-monitor-form"', $fragment);
        self::assertMatchesRegularExpression('#data-nowo-ui-dialog-actions[^>]*>\s*<button type="button"[^>]*data-nowo-ui-dialog-close>[^<]*</button>\s*<button type="submit" form="uptime-monitor-form"#', $fragment);
    }

    public function testTheMonitorPageRegionsFollowWebUiMonitorShow(): void
    {
        $page = static fn (array $layout): array => ['monitor_layout' => $layout] + PageFixtures::monitorShow();

        $slots = $this->renderPage(new PageFixture(self::VIEWS . 'monitor/show.html.twig', $page([
            'charts' => 'host',
            'slots'  => ['figures' => 'host/_figures.html.twig', 'charts' => 'host/_charts.html.twig', 'details' => 'host/_details.html.twig'],
        ])))->html;
        self::assertStringNotContainsString('<canvas', $slots, 'charts: host draws no bundle chart');
        self::assertStringNotContainsString('uptime-metrics-cards', $slots);
        self::assertStringNotContainsString('uptime-details-header', $slots);
        self::assertStringContainsString('id="uptime-monitor-detail-root"', $slots, 'the live root stays');
        self::assertStringContainsString('data-events-table', $slots, 'the check log stays');
        $details = strpos($slots, '<p class="host-details">API health</p>');
        $figures = strpos($slots, '<p class="host-figures">97.25</p>');
        $charts  = strpos($slots, '<p class="host-charts">/main/api/monitors/7/history</p>');
        self::assertIsInt($details);
        self::assertIsInt($figures);
        self::assertIsInt($charts);
        self::assertTrue($details < $figures && $figures < $charts, 'the slots render in the page order');

        $hostless = $this->renderPage(new PageFixture(self::VIEWS . 'monitor/show.html.twig', $page(['charts' => 'host', 'slots' => []])))->html;
        self::assertStringNotContainsString('uptime-detail-charts', $hostless);
        self::assertStringContainsString('uptime-metrics-cards', $hostless);
        self::assertStringContainsString('uptime-details-header', $hostless);

        $none = $this->renderPage(new PageFixture(self::VIEWS . 'monitor/show.html.twig', $page(['charts' => 'none', 'slots' => ['charts' => 'host/_charts.html.twig']])))->html;
        self::assertStringNotContainsString('uptime-detail-charts', $none);
        self::assertStringNotContainsString('host-charts', $none, 'charts: none ignores the slot');

        $default = $this->renderPage(new PageFixture(self::VIEWS . 'monitor/show.html.twig', $page(['charts' => 'bundle', 'slots' => ['charts' => 'host/_charts.html.twig']])))->html;
        self::assertStringContainsString('id="uptime-chart-uptime"', $default);
        self::assertStringNotContainsString('host-charts', $default);
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function monitorShowBcCases(): iterable
    {
        foreach (['plain', 'group', 'paged', 'push', 'push file-managed', 'slots', 'charts none'] as $case) {
            foreach (['auto', 'dark'] as $theme) {
                yield $case . ' / ' . $theme => [$case, $theme];
            }
        }
    }

    /**
     * 1.30 BC pin: with nothing of 1.30 configured (the processed defaults of web_ui.monitor_show, no root attributes,
     * no log sort, the history URL kept) the monitor page renders byte for byte what the v1.29.0 template renders
     * (tests/Fixtures/bc/monitor_show_v1.29.0.html.twig.txt, a verbatim copy) with the same variables.
     */
    #[DataProvider('monitorShowBcCases')]
    public function testMonitorShowUnsetOutputIsTheV129One(string $case, string $theme): void
    {
        $defaults = (new Processor())->processConfiguration(new Configuration(), [[]])['web_ui']['monitor_show'];
        $context  = match ($case) {
            'group'             => PageFixtures::monitorShow(true),
            'paged'             => PageFixtures::monitorShowPaged(),
            'push'              => PageFixtures::monitorShowPush(),
            'push file-managed' => PageFixtures::monitorShowPush(true),
            default             => PageFixtures::monitorShow(),
        };
        if ($case === 'slots') {
            $defaults['charts'] = 'host';
            $defaults['slots']  = ['figures' => 'host/_figures.html.twig', 'charts' => 'host/_charts.html.twig', 'details' => 'host/_details.html.twig', 'history' => null, 'log' => null];
        }
        if ($case === 'charts none') {
            $defaults['charts'] = 'none';
        }
        $layout = MonitorShowLayout::resolve($defaults);
        $old    = ['monitor_layout' => ['charts' => $layout['charts'], 'slots' => $layout['slots']]] + $context;
        $new    = ['monitor_layout' => $layout, 'monitor_root_attributes' => [], 'log_sort' => null] + $context;

        self::assertSame(
            $this->renderWithTheme($theme, new PageFixture('bc/monitor_show_v1.29.0.html.twig', $old)),
            $this->renderWithTheme($theme, new PageFixture(self::VIEWS . 'monitor/show.html.twig', $new)),
        );
    }

    public function testTheMonitorPageRegionsFollowTheConfiguredOrder(): void
    {
        $layout = MonitorShowLayout::resolve(['order' => ['figures', 'history', 'details', 'charts', 'log']]);
        self::assertSame(['figures', 'history', 'details', 'charts', 'log', 'children', 'push'], $layout['order']);
        $html = $this->renderPage(new PageFixture(self::VIEWS . 'monitor/show.html.twig', ['monitor_layout' => $layout] + PageFixtures::monitorShow()))->html;

        $root    = strpos($html, 'id="uptime-monitor-detail-root"');
        $figures = strpos($html, 'uptime-metrics-cards');
        $history = strpos($html, 'uptime-detail-history-section');
        $details = strpos($html, 'uptime-details-header');
        $charts  = strpos($html, 'uptime-detail-charts');
        $log     = strpos($html, 'data-events-table');
        self::assertIsInt($root);
        self::assertIsInt($figures);
        self::assertIsInt($history);
        self::assertIsInt($details);
        self::assertIsInt($charts);
        self::assertIsInt($log);
        self::assertTrue($root < $figures && $figures < $history && $history < $details && $details < $charts && $charts < $log, 'figures → history → details (inside the root) → charts → log');
        self::assertSame(1, substr_count($html, 'uptime-details-header'), 'details render once, inside the root');
        self::assertSame(4, substr_count($html, 'data-detail-pending'), 'one pending wrapper per check-dependent region');
        self::assertStringContainsString('data-uptime-region="figures"', $html);
        self::assertStringContainsString('data-detail-status', $html, 'the status badge stays in the details');

        // A monitor without checks: the notice first, every pending region hidden; an empty group renders nothing.
        $unchecked = $this->renderPage(new PageFixture(self::VIEWS . 'monitor/show.html.twig', ['monitor_layout' => MonitorShowLayout::resolve(['order' => ['children', 'log']])] + PageFixtures::monitorShow(true)))->html;
        $notice    = strpos($unchecked, 'data-detail-unchecked');
        $children  = strpos($unchecked, 'uptime-group-children');
        self::assertIsInt($notice);
        self::assertIsInt($children);
        self::assertTrue($notice < $children);
        // 1.38 (UX-018): the group children are a flat section (heading + divider), not a card.
        self::assertMatchesRegularExpression('#<section class="uptime-group-children uptime-flat-section">\s*<h2 class="uptime-flat-section__title">#', $unchecked);
        self::assertSame(4, preg_match_all('#data-detail-pending data-uptime-region="[a-z]+" hidden>#', $unchecked));
        self::assertStringContainsString('uptime-details-header', $unchecked, 'details not listed stay in the sections');

        // charts: none leaves no empty wrapper.
        $none = $this->renderPage(new PageFixture(self::VIEWS . 'monitor/show.html.twig', ['monitor_layout' => MonitorShowLayout::resolve(['charts' => 'none', 'order' => ['log']])] + PageFixtures::monitorShow()))->html;
        self::assertStringNotContainsString('data-uptime-region="charts"', $none);
        self::assertStringContainsString('uptime-push-credentials', $this->renderPage(new PageFixture(self::VIEWS . 'monitor/show.html.twig', ['monitor_layout' => MonitorShowLayout::resolve(['order' => ['push']])] + PageFixtures::monitorShowPush()))->html);
    }

    public function testTheHistoryAndLogSlotsReplaceTheirRegions(): void
    {
        $layout = MonitorShowLayout::resolve(['slots' => ['history' => 'host/_history.html.twig', 'log' => 'host/_log.html.twig']]);
        $html   = $this->renderPage(new PageFixture(self::VIEWS . 'monitor/show.html.twig', ['monitor_layout' => $layout] + PageFixtures::monitorShowPaged()))->html;

        self::assertStringContainsString('<p class="host-history">1</p>', $html);
        self::assertStringContainsString('<p class="host-log">1 / 2</p>', $html);
        self::assertStringNotContainsString('uptime-detail-history-section', $html);
        self::assertStringNotContainsString('data-events-table', $html);
        self::assertStringContainsString('uptime-metrics-cards', $html, 'the other regions stay');
    }

    public function testTheCheckLogOptions(): void
    {
        $layout = MonitorShowLayout::resolve(['log' => ['sortable' => true, 'align_numbers' => true, 'time_format' => 'H:i', 'time_title_format' => 'Y-m-d H:i:s']]);
        $sort   = (new MonitorLogSort('latency', 'asc'))->view(['status' => '/s?log_sort=status&log_dir=asc', 'time' => '/s?log_sort=time&log_dir=desc', 'latency' => '/s?log_sort=latency&log_dir=desc', 'code' => '/s?log_sort=code&log_dir=asc']);
        $html   = $this->renderPage(new PageFixture(self::VIEWS . 'monitor/show.html.twig', ['monitor_layout' => $layout, 'log_sort' => $sort] + PageFixtures::monitorShow()))->html;

        self::assertStringContainsString('<time datetime="2026-10-26T10:00:00+00:00" title="2026-10-26 10:00:00">10:00</time>', $html);
        self::assertStringContainsString('<td class="uptime-cell-num" data-uptime-phone="meta">1200 ms</td>', $html);
        self::assertStringContainsString('<td class="uptime-cell-num" data-nowo-ui-priority="3"', $html);
        self::assertMatchesRegularExpression('#<th scope="col" class="uptime-cell-num" aria-sort="ascending"><a class="uptime-sort-link" href="/s\?log_sort=latency&amp;log_dir=desc" data-log-sort="latency" title="Sort by Latency">Latency<span class="uptime-sort-indicator" aria-hidden="true">▲</span></a></th>#', $html);
        self::assertStringContainsString('<th scope="col" class="uptime-cell-num" data-nowo-ui-priority="3"><a class="uptime-sort-link" href="/s?log_sort=code&amp;log_dir=asc"', $html);
        self::assertSame(1, substr_count($html, 'aria-sort='), 'only the sorted column');
        self::assertSame(4, substr_count($html, 'data-log-sort='), 'status, time, latency, code (not the message)');

        // Alignment alone: plain headers with the number class; descending indicator.
        $aligned = $this->renderPage(new PageFixture(self::VIEWS . 'monitor/show.html.twig', ['monitor_layout' => MonitorShowLayout::resolve(['log' => ['align_numbers' => true]])] + PageFixtures::monitorShow()))->html;
        self::assertStringContainsString('<th scope="col" class="uptime-cell-num">Latency</th>', $aligned);
        self::assertStringNotContainsString('uptime-sort-link', $aligned);
        $desc = $this->renderPage(new PageFixture(self::VIEWS . 'monitor/show.html.twig', ['monitor_layout' => MonitorShowLayout::resolve(['log' => ['sortable' => true]]), 'log_sort' => (new MonitorLogSort())->view(['status' => '/a', 'time' => '/b', 'latency' => '/c', 'code' => '/d'])] + PageFixtures::monitorShow()))->html;
        self::assertStringContainsString('aria-sort="descending"', $desc);
        self::assertStringContainsString('▼', $desc);
        self::assertStringContainsString('<th scope="col"><a class="uptime-sort-link" href="/a"', $desc);
    }

    public function testTheHeaderStatusBadgeAndSplitActions(): void
    {
        $context         = PageFixtures::monitorShow();
        $page            = $context['page'];
        $context['page'] = new ShowPage(
            new PageHeader($page->header->title, $page->header->intro, [
                (new Action('edit', UptimePages::label('monitor.edit'), '/main/monitors/7/edit'))->asIcon('tabler:pencil'),
                Action::delete('/main/monitors/7/delete', 'token-delete7', null, UptimePages::label('monitor.delete')),
            ], actionsLayout: 'split'),
            $page->sections,
            bundle: UptimePages::BUNDLE,
            attributes: ['data-testid' => 'uptime-monitor'],
        );
        $html = $this->renderPage(new PageFixture(self::VIEWS . 'monitor/show.html.twig', ['monitor_layout' => MonitorShowLayout::resolve(['header' => ['status' => true, 'actions_layout' => 'split']])] + $context))->html;

        self::assertSame(1, preg_match('#<span class="[^"]*uptime-status-badge uptime-status-badge--down" data-nowo-ui-page-status="danger"[^>]*data-detail-status[^>]*>Down</span>#', $html), 'the live badge in the page header');
        self::assertSame(1, substr_count($html, 'data-detail-status'), 'not repeated in the details card');
        self::assertStringNotContainsString('uptime-details-status', $html);
        self::assertStringContainsString('data-testid="uptime-monitor-status"', $html);
        self::assertSame(1, preg_match('#data-status-badges="([^"]+)"#', $html, $badges));
        $decoded = json_decode(html_entity_decode($badges[1], ENT_QUOTES | ENT_HTML5), true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);
        self::assertSame(['up', 'down', 'degraded', 'unknown'], array_keys($decoded));
        self::assertSame('danger', $decoded['down']['tone']);
        self::assertStringContainsString('uptime-status-badge--down', $decoded['down']['class']);

        $unknown = $this->renderPage(new PageFixture(self::VIEWS . 'monitor/show.html.twig', ['monitor_layout' => MonitorShowLayout::resolve(['header' => ['status' => true]])] + PageFixtures::monitorShow(true)))->html;
        self::assertSame(1, preg_match('#data-nowo-ui-page-status="neutral"[^>]*>Unknown</span>#', $unknown));
    }

    public function testTheLiveUpdatesRootHooks(): void
    {
        $html = $this->renderPage(new PageFixture(self::VIEWS . 'monitor/show.html.twig', [
            'api_history_url'         => null,
            'monitor_root_attributes' => ['class' => 'host-root "x"', 'data-controller' => 'live-updates-watch', 'data-live-updates-watch-url-value' => '/main/api/monitors/7/history?a=1&b="2"', 'data-ready' => true],
        ] + PageFixtures::monitorShow()))->html;

        self::assertStringNotContainsString('data-api-history', $html, 'no history URL: no polling');
        self::assertStringContainsString('class="uptime-detail host-root &quot;x&quot;" id="uptime-monitor-detail-root"', $html);
        self::assertStringContainsString(' data-controller="live-updates-watch"', $html);
        self::assertStringContainsString(' data-live-updates-watch-url-value="/main/api/monitors/7/history?a=1&amp;b=&quot;2&quot;"', $html);
        self::assertStringContainsString(' data-ready>', $html);
        self::assertSame(1, substr_count($html, 'class="uptime-detail '), 'class is appended, not repeated');
    }

    public function testGuL2APushPageWithoutTheUrlPrintsNoIngestUrl(): void
    {
        $fixture = PageFixtures::monitorShowPush();
        self::assertIsArray($fixture['push']);
        $fixture['push']['url'] = null;

        $html = $this->renderPage(new PageFixture(self::VIEWS . 'monitor/show.html.twig', $fixture))->html;

        self::assertStringNotContainsString('uptime-push-url', $html);
        self::assertStringContainsString('uptime-push-token-state', $html, 'the rest of the card stays');
        self::assertStringContainsString('uptime-push-url', $this->renderPage(new PageFixture(self::VIEWS . 'monitor/show.html.twig', PageFixtures::monitorShowPush()))->html);
    }

    public function testAFileManagedMonitorIsMarkedOnItsPageAndInTheDashboard(): void
    {
        $push = $this->renderPage(new PageFixture(self::VIEWS . 'monitor/show.html.twig', PageFixtures::monitorShowPush(true)))->html;
        self::assertStringContainsString('data-testid="uptime-push-file-managed"', $push);

        $table = $this->renderPage(new PageFixture(self::VIEWS . 'dashboard/index.html.twig', PageFixtures::dashboardTable(false, true)))->html;
        self::assertStringContainsString('data-testid="uptime-monitor-file-badge"', $table);
        self::assertStringContainsString('Managed from file (little-vps.resources)', $table);
    }

    public function testTheShowPageHeaderCarriesTheOriginBadgeEscaped(): void
    {
        $fixture = PageFixtures::monitorShowPush();
        $plain   = $this->renderPage(new PageFixture(self::VIEWS . 'monitor/show.html.twig', $fixture))->html;
        self::assertStringNotContainsString('uptime-monitor-origin-badge', $plain, 'a UI monitor has no badge');

        $monitor = $fixture['monitor'];
        self::assertInstanceOf(Monitor::class, $monitor);
        $monitor->markFileManaged('<script>alert(1)</script>');
        $html = $this->renderPage(new PageFixture(self::VIEWS . 'monitor/show.html.twig', $fixture))->html;
        self::assertMatchesRegularExpression('#<header [^>]*uptime-details-header[^>]*>.*data-testid="uptime-monitor-origin-badge">Managed from file \(&lt;script&gt;alert\(1\)&lt;/script&gt;\)</span>#s', $html);
        self::assertStringNotContainsString('<script>alert(1)', $html);
        self::assertStringContainsString('data-testid="uptime-push-file-managed"', $html);
    }

    public function testTheFormRendersTheSelectedTypeAndNoEmptySection(): void
    {
        $render = function (MonitorType $type, array $hidden = [], ?string $errorOn = null): string {
            /** @var list<string> $hidden */
            $data       = new MonitorFormData();
            $data->type = $type;
            $form       = PageFixtures::forms($hidden)->create(MonitorFormType::class, $data);
            if ($errorOn !== null) {
                $form->get($errorOn)->addError(new FormError('Invalid.'));
            }

            return $this->createTwig()->render(self::VIEWS . 'monitor/_monitor_form_modal.html.twig', ['form' => $form->createView()] + PageFixtures::monitorFormFragment());
        };
        $state = static function (string $html, string $key): string {
            self::assertMatchesRegularExpression('#data-monitor-fields="' . $key . '"( hidden)?[ >]#', $html, $key);
            preg_match('#data-monitor-fields="' . $key . '"( hidden)?[ >]#', $html, $match);

            return ($match[1] ?? '') === ' hidden' ? 'hidden' : 'shown';
        };

        $push = $render(MonitorType::Push);
        self::assertSame(
            ['shown', 'hidden', 'hidden', 'hidden', 'hidden', 'shown'],
            [$state($push, 'check'), $state($push, 'check-retry'), $state($push, 'check-timeout'), $state($push, 'http'), $state($push, 'tcp'), $state($push, 'push')],
        );
        $ping = $render(MonitorType::Ping);
        self::assertSame(['shown', 'hidden', 'shown', 'hidden'], [$state($ping, 'tcp'), $state($ping, 'tcp-port'), $state($ping, 'check-timeout'), $state($ping, 'push')]);
        $group = $render(MonitorType::Group);
        self::assertSame(['hidden', 'hidden', 'hidden'], [$state($group, 'check'), $state($group, 'http-advanced'), $state($group, 'dns')]);
        self::assertStringContainsString('name="monitor_form[paused]"', $group, 'a group can be paused from the form');

        // A field with an error opens the folded Advanced block.
        self::assertMatchesRegularExpression('#data-monitor-fields="http-advanced" open>#', $render(MonitorType::Https, [], 'maxRedirects'));

        // A host hiding every threshold gets no empty thresholds section (no orphan legend).
        $thresholds = [];
        foreach (['Disk', 'Inodes', 'Memory', 'Cpu', 'Swap', 'Load'] as $metric) {
            $thresholds[] = 'push' . $metric . 'Warn';
            $thresholds[] = 'push' . $metric . 'Critical';
        }
        self::assertStringContainsString('uptime-form-section--thresholds', $push);
        self::assertStringNotContainsString('uptime-form-section--thresholds', $render(MonitorType::Push, $thresholds));
        self::assertStringContainsString('uptime-form-section--thresholds', $render(MonitorType::Push, ['pushDiskWarn']));
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

    /** 1.37 (UX-049): the row More actions is UiKit's square kebab icon button, with the confirm dialog outside the popover. */
    public function testTheRowMoreActionsIsTheSquareKebabIconButton(): void
    {
        $table = $this->renderPage(new PageFixture(self::VIEWS . 'dashboard/index.html.twig', PageFixtures::dashboardTable()))->html;

        self::assertStringContainsString('data-uptime-row-actions', $table);
        self::assertStringContainsString('id="uptime-monitor-menu-7-panel"', $table);
        self::assertMatchesRegularExpression('#<button type="button" class="[^"]*nowo-ui-menu__toggle--square[^"]*"[^>]*aria-haspopup="menu"[^>]*aria-label="More actions"#', $table);
        self::assertStringNotContainsString('class="nowo-ui-menu__label">More actions', $table, 'icon only: no visible label');
        self::assertStringContainsString('nowo-ui-menu__item--danger', $table, 'Delete is the danger item');
        self::assertStringContainsString('<dialog', $table);
        self::assertStringNotContainsString('data-nowo-ui-page-actions', $table, 'a row menu is not the page actions slot');
        // the shared delete confirmation dialog sits after the table, not inside the popover panel
        self::assertGreaterThan(strpos($table, 'id="uptime-monitor-menu-7-panel"'), strpos($table, '<dialog id="uptime-monitor-confirm"'));
    }

    /**
     * Lazy dialogs (symfony-beacon rule 2026-11-23): N monitor rows print ONE shared confirm dialog, not N. Each row
     * keeps its own hidden delete form (target + CSRF token) that the shared dialog submits through `form="…"`.
     */
    public function testMonitorRowsShareOneConfirmDialog(): void
    {
        $html = $this->renderPage(new PageFixture(self::VIEWS . 'dashboard/index.html.twig', PageFixtures::dashboardTableMany(50)))->html;
        if (getenv('UPTIME_LAZY_MEASURE') !== false) {
            fwrite(STDERR, sprintf("\n[lazy-dialogs] uptime table dashboard, 50 monitors: %d bytes, %d dialogs\n", strlen($html), preg_match_all('/<dialog\b/', $html)));
        }

        self::assertSame(1, substr_count($html, 'data-nowo-confirm-shared'), 'One shared confirm dialog for 50 rows.');
        self::assertSame(1, preg_match_all('/<dialog [^>]*data-nowo-ui-confirm[ >]/', $html), 'One confirm dialog (the others are the row detail and the monitor form shells).');
        self::assertSame(50, substr_count($html, 'data-nowo-confirm-target="uptime-monitor-confirm"'));
        $dialog = substr($html, (int) strpos($html, '<dialog id="uptime-monitor-confirm"'));
        self::assertStringNotContainsString('<form', substr($dialog, 0, (int) strpos($dialog, '</dialog>')), 'The shared dialog carries no form.');
        for ($id = 100; $id < 150; ++$id) {
            self::assertSame(1, preg_match('/<form [^>]*action="\/main\/monitors\/' . $id . '\/delete"[^>]*>/', $html, $form), 'Row ' . $id . ' keeps its delete form.');
            self::assertSame(1, preg_match('/ id="([^"]+)"/', $form[0], $formId));
            self::assertStringContainsString('data-nowo-confirm-form="' . $formId[1] . '"', $html, 'Its toggle names its own form.');
            self::assertStringContainsString('value="token-delete' . $id . '"', $html, 'Its own CSRF token.');
            self::assertStringContainsString('action="/main/monitors/' . $id . '/toggle-pause"', $html, 'The other POST actions stay in the menu.');
        }
        self::assertStringContainsString('data-nowo-confirm-message="Delete the monitor “Monitor 7” and its check history? This cannot be undone."', $html);
    }

    /** Lazy dialogs: the tag rows of Settings → Tags share one confirm dialog and keep their own delete forms. */
    public function testTagRowsShareOneConfirmDialog(): void
    {
        $tags = $this->renderPage(new PageFixture(self::VIEWS . 'settings/tags.html.twig', PageFixtures::settingsPages()['tags']))->html;

        self::assertSame(1, substr_count($tags, 'data-nowo-confirm-shared'));
        self::assertStringContainsString('<dialog id="uptime-tag-confirm"', $tags);
        self::assertStringContainsString('data-nowo-confirm-target="uptime-tag-confirm"', $tags);
        self::assertMatchesRegularExpression('#<form [^>]*action="/main/settings/tags/1/delete"#', $tags);
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

    /**
     * 1.24 (MUX-21, MUX-101): the lists carry the phone layout hooks; the status cell is marked to come first, the meta
     * cells carry their labels, a check without an HTTP code drops the code cell on phones.
     */
    public function testListsCarryThePhoneLayoutHooks(): void
    {
        $table = $this->renderPage(new PageFixture(self::VIEWS . 'dashboard/index.html.twig', PageFixtures::dashboardTable()))->html;
        self::assertStringContainsString('class="uptime-dashboard-monitors uptime-phone-list"', $table);
        self::assertStringContainsString('class="uptime-dashboard-events uptime-phone-list"', $table);
        self::assertMatchesRegularExpression('#<table class="[^"]* uptime-phone-table" data-responsive-table-target="table" data-testid="uptime-monitors-table">#', $table);
        self::assertMatchesRegularExpression('#<table class="[^"]* uptime-phone-table" data-testid="uptime-events-table">#', $table);
        self::assertStringContainsString('<td data-column="monitor" data-uptime-phone="title">', $table);
        self::assertMatchesRegularExpression('#<td data-column="status" data-uptime-phone="status">\s*<span class="[^"]*nowo-ui-badge#', $table);
        self::assertMatchesRegularExpression('#<td data-column="uptime" [^>]*data-uptime-phone="meta" data-label="Uptime 24 h">#', $table);
        self::assertMatchesRegularExpression('#<td data-column="last_check" [^>]*data-nowo-ui-priority="2" data-uptime-phone="meta" data-label="[^"]+">#', $table);
        self::assertMatchesRegularExpression('#<td data-column="interval" [^>]*data-nowo-ui-priority="3" data-uptime-phone="meta"#', $table);
        self::assertStringContainsString('<td data-column="actions" data-uptime-phone="actions">', $table);
        self::assertStringContainsString('<td class="uptime-event-message" data-uptime-phone="detail">HTTP 200</td>', $table);

        $show = $this->renderPage(new PageFixture(self::VIEWS . 'monitor/show.html.twig', PageFixtures::monitorShow()))->html;
        self::assertMatchesRegularExpression('#<section class="uptime-events uptime-phone-list #', $show);
        self::assertStringContainsString('<table class="uptime-events-table uptime-phone-table ', $show);
        self::assertStringContainsString('<td data-uptime-phone="status"><span class="uptime-status uptime-status-down">Down</span></td>', $show);
        self::assertStringContainsString('<td data-uptime-phone="meta">1200 ms</td>', $show);
        self::assertStringContainsString('<td data-nowo-ui-priority="3" data-uptime-phone="meta" data-label="Code">503</td>', $show);
        self::assertStringContainsString('<td class="uptime-event-message" data-uptime-phone="detail">Service unavailable</td>', $show);

        $context                                 = PageFixtures::monitorShowPaged();
        $context['log_events'][0]['status_code'] = null;
        $noCode                                  = $this->renderPage(new PageFixture(self::VIEWS . 'monitor/show.html.twig', $context))->html;
        self::assertStringContainsString('<td data-nowo-ui-priority="3" data-uptime-phone="hidden" data-label="Code">—</td>', $noCode);
    }

    /** 1.24 (MUX-21, MUX-100): the committed stylesheet carries the phone rules. */
    public function testTheStylesheetCarriesThePhoneLayout(): void
    {
        $css = preg_replace('/\s+/', '', (string) file_get_contents(dirname(__DIR__, 3) . '/src/Resources/public/uptime-dashboard.css')) ?? '';
        self::assertStringContainsString('container:uptime-phone-list/inline-size', $css);
        // The build may write the range syntax (width<=…) for max-width.
        self::assertMatchesRegularExpression('#@containeruptime-phone-list\((max-width:|width<=)39\.99rem\)#', $css);
        self::assertMatchesRegularExpression('#\[data-uptime-phone=["\']?status["\']?\]\{[^}]*order:-2#', $css);
        self::assertMatchesRegularExpression('#@media\((max-width:|width<=)30rem\)\{\.uptime-metrics-cards\{[^}]*grid-template-columns:repeat\(2,minmax\(0,1fr\)\)#', $css);
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
            'host/_section.html.twig' => '<p class="host-section">{{ note }}</p>',
            // web_ui.monitor_show slots (1.21): rendered with the page variables.
            'host/_details.html.twig' => '<p class="host-details">{{ monitor.name }}</p>',
            'host/_figures.html.twig' => '<p class="host-figures">{{ detail.uptime_24h }}</p>',
            'host/_charts.html.twig'  => '<p class="host-charts">{{ api_history_url }}</p>',
            // web_ui.monitor_show slots (1.30).
            'host/_history.html.twig' => '<p class="host-history">{{ detail.history|length }}</p>',
            'host/_log.html.twig'     => '<p class="host-log">{{ log_pagination.page }} / {{ log_pagination.totalPages }}</p>',
            // The stub page header also prints the rendered status badge (web_ui.monitor_show.header.status, 1.30).
            StubHost::PARTIAL_TEMPLATE => StubHost::templates()[StubHost::PARTIAL_TEMPLATE] . '{% if status_badge|default(null) %}<div data-pc-status>{{ status_badge|raw }}</div>{% endif %}',
            // The v1.29.0 monitor page, verbatim (1.30 BC pin).
            'bc/monitor_show_v1.29.0.html.twig' => (string) file_get_contents(dirname(__DIR__, 2) . '/Fixtures/bc/monitor_show_v1.29.0.html.twig.txt'),
            'host/tenants_override.html.twig'   => "{% extends '@NowoUptimeMonitorBundle/tenant/index.html.twig' %}{% block nowo_ui_list_after %}<p class=\"host-note\">Managed by the host.</p>{% endblock %}",
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
