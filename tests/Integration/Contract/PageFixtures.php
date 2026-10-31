<?php

declare(strict_types=1);

namespace Nowo\UptimeMonitorBundle\Tests\Integration\Contract;

use DateTimeImmutable;
use Nowo\FormKitBundle\Form\Constraint\ConstraintDefinitionFactory;
use Nowo\FormKitBundle\Form\FormOptionsMerger;
use Nowo\UiKitBundle\View\Action;
use Nowo\UiKitBundle\View\BackLink;
use Nowo\UiKitBundle\View\Column;
use Nowo\UiKitBundle\View\Confirm;
use Nowo\UiKitBundle\View\DashboardPage;
use Nowo\UiKitBundle\View\DetailItem;
use Nowo\UiKitBundle\View\DetailSection;
use Nowo\UiKitBundle\View\EmptyState;
use Nowo\UiKitBundle\View\FormPage;
use Nowo\UiKitBundle\View\Label;
use Nowo\UiKitBundle\View\ListPage;
use Nowo\UiKitBundle\View\PageHeader;
use Nowo\UiKitBundle\View\Pagination;
use Nowo\UiKitBundle\View\SettingsPage;
use Nowo\UiKitBundle\View\SettingsSection;
use Nowo\UiKitBundle\View\ShowPage;
use Nowo\UptimeMonitorBundle\Entity\CheckResult;
use Nowo\UptimeMonitorBundle\Entity\Monitor;
use Nowo\UptimeMonitorBundle\Entity\Tag;
use Nowo\UptimeMonitorBundle\Entity\Tenant;
use Nowo\UptimeMonitorBundle\Enum\CheckStatus;
use Nowo\UptimeMonitorBundle\Enum\MonitorType;
use Nowo\UptimeMonitorBundle\Form\BackupImportFormType;
use Nowo\UptimeMonitorBundle\Form\CsrfActionFormType;
use Nowo\UptimeMonitorBundle\Form\Model\MonitorFormData;
use Nowo\UptimeMonitorBundle\Form\Model\SettingsAppearanceData;
use Nowo\UptimeMonitorBundle\Form\Model\SettingsGeneralData;
use Nowo\UptimeMonitorBundle\Form\Model\SettingsHistoryData;
use Nowo\UptimeMonitorBundle\Form\Model\SettingsReverseProxyData;
use Nowo\UptimeMonitorBundle\Form\Model\SettingsStatusPageData;
use Nowo\UptimeMonitorBundle\Form\Model\TagFormData;
use Nowo\UptimeMonitorBundle\Form\MonitorFormType;
use Nowo\UptimeMonitorBundle\Form\MonitorSearchFormType;
use Nowo\UptimeMonitorBundle\Form\SettingsAppearanceFormType;
use Nowo\UptimeMonitorBundle\Form\SettingsGeneralFormType;
use Nowo\UptimeMonitorBundle\Form\SettingsHistoryFormType;
use Nowo\UptimeMonitorBundle\Form\SettingsReverseProxyFormType;
use Nowo\UptimeMonitorBundle\Form\SettingsStatusPageFormType;
use Nowo\UptimeMonitorBundle\Form\TagFormType;
use Nowo\UptimeMonitorBundle\Form\TenantFormType;
use Nowo\UptimeMonitorBundle\Ui\UptimeAreaNav;
use Nowo\UptimeMonitorBundle\Ui\UptimePages;
use ReflectionProperty;
use Symfony\Component\Form\Extension\Csrf\CsrfExtension;
use Symfony\Component\Form\Extension\Validator\ValidatorExtension;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\Form\Forms;
use Symfony\Component\Form\FormView;
use Symfony\Component\Translation\Loader\YamlFileLoader;
use Symfony\Component\Translation\Translator;
use Symfony\Component\Validator\Validation;

use function basename;
use function dirname;
use function explode;
use function glob;
use function method_exists;

use const JSON_THROW_ON_ERROR;

/**
 * Page contexts the controllers build (view models + 1.x variables), with deterministic entities and forms, for
 * {@see PageContractTest}.
 */
final class PageFixtures
{
    public const SLUG = 'main';

    /**
     * @return array<string, mixed>
     */
    public static function tenantIndex(bool $empty = false): array
    {
        $tenants = $empty ? [] : [self::tenant(), new Tenant('acme', 'Acme Corp')];

        return [
            'page' => new ListPage(
                new PageHeader(UptimePages::label('tenant.index.title'), null, [Action::create('/tenants/new', UptimePages::label('tenant.index.new'))]),
                [new Column('name', UptimePages::label('tenant.col.name')), new Column('slug', UptimePages::label('tenant.col.slug'))],
                $tenants,
                empty: new EmptyState(UptimePages::label('tenant.index.empty')),
                bundle: UptimePages::BUNDLE,
                attributes: ['data-testid' => 'uptime-tenants'],
            ),
            'tenants' => $tenants,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function tenantForm(): array
    {
        $form = self::forms()->create(TenantFormType::class, ['slug' => '', 'name' => ''])->createView();

        return [
            'page' => new FormPage(
                new PageHeader(UptimePages::label('tenant.new.title'), back: new BackLink('/tenants', UptimePages::label('tenant.back'))),
                $form,
                UptimePages::label('tenant.form.create'),
                '/tenants',
                bundle: UptimePages::BUNDLE,
                formThemes: self::formThemes(),
            ),
            'form'  => $form,
            'title' => 'tenant.new.title',
        ] + self::shared();
    }

    /**
     * @return array<string, mixed>
     */
    public static function dashboard(bool $empty = false, bool $filtered = false): array
    {
        $tenant  = self::tenant();
        $monitor = self::monitor(7, 'API health', MonitorType::Https, 'https://example.com/health');
        $latest  = self::check($monitor, CheckStatus::Up, 42);
        $groups  = $empty ? [] : [[
            'label'         => 'Production',
            'group_monitor' => null,
            'group_row'     => null,
            'children'      => [[
                'monitor'    => $monitor,
                'latest'     => $latest,
                'history'    => [['status' => 'empty', 'checked_at' => ''], ['status' => 'up', 'checked_at' => '2026-10-26T10:00:00+00:00']],
                'uptime_24h' => 99.5,
                'uptime_30d' => 99.9,
                'nested'     => false,
            ]],
        ]];
        $events = $empty ? [] : [[
            'monitor_id'   => 7,
            'monitor_name' => 'API health',
            'status'       => 'up',
            'checked_at'   => '2026-10-26T10:00:00+00:00',
            'message'      => 'HTTP 200',
            'status_code'  => 200,
            'is_group'     => false,
        ]];

        return [
            'page' => new DashboardPage(
                new PageHeader('Main', null, [
                    new Action('custom', UptimePages::label('dashboard.settings'), '/main/settings', icon: 'config'),
                    new Action('view', UptimePages::label('dashboard.status_page'), '/status/main'),
                    new Action('create', UptimePages::label('monitor.new'), '/main/monitors/new', variant: 'primary', attributes: ['data-monitor-form-open' => true, 'data-monitor-form-url' => '/main/monitors/new', 'data-monitor-form-return' => 'dashboard']),
                ]),
                bundle: UptimePages::BUNDLE,
                attributes: ['data-testid' => 'uptime-dashboard'],
            ),
            'tenant'                   => $tenant,
            'tenant_slug'              => self::SLUG,
            'uptime_search_index'      => false,
            'heartbeat_bar_theme'      => 'normal',
            'elapsed_time_display'     => 'show',
            'groups'                   => $groups,
            'stats'                    => ['up' => $empty ? 0 : 1, 'down' => 0, 'degraded' => 0, 'unknown' => 0, 'paused' => 0],
            'events'                   => $events,
            'events_filter_monitor'    => $filtered ? $monitor : null,
            'events_filter_monitor_id' => $filtered ? 7 : null,
            'dashboard_sync'           => $filtered ? 'mercure' : 'polling',
            'poll_interval_ms'         => 30000,
            'api_summary_url'          => '/main/api/summary',
            'layout_fragment_url'      => '/main/fragment/layout',
            'overview_chart_json'      => '{}',
            'mercure_hub_url'          => $filtered ? 'https://hub.example/.well-known/mercure' : null,
            'mercure_topic'            => $filtered ? '/uptime/main' : null,
            'monitor_search_form'      => self::forms()->create(MonitorSearchFormType::class)->createView(),
        ] + self::shared();
    }

    /**
     * The table dashboard (`web_ui.dashboard.view: table`), with a host section after it.
     *
     * @return array<string, mixed>
     */
    public static function dashboardTable(bool $empty = false): array
    {
        $context = self::dashboard($empty);
        $rows    = $empty ? [] : $context['groups'][0]['children'];

        return [
            'dashboard_view'      => 'table',
            'dashboard_header'    => $empty ? 'tenant' : 'monitors',
            'monitor_rows'        => $rows,
            'monitor_row_actions' => $empty ? [] : [7 => [
                Action::view('/main/monitors/7', UptimePages::label('monitor.view')),
                Action::delete('/main/monitors/7/delete', 'token-delete7', new Confirm(
                    UptimePages::label('monitor.delete'),
                    UptimePages::label('monitor.delete_confirm', ['%name%' => 'API health']),
                    UptimePages::label('monitor.delete'),
                    id: 'uptime-monitor-delete-7',
                ), UptimePages::label('monitor.delete_short')),
            ]],
            'monitor_targets'   => [7 => 'https://example.com/health'],
            'dashboard_empty'   => new EmptyState(UptimePages::label('dashboard.empty.title'), UptimePages::label('dashboard.no_monitors'), Action::create('/main/monitors/new', UptimePages::label('monitor.new'))),
            'events_page'       => $context['events'],
            'events_pagination' => new Pagination(1, 1, 2, 2, 'nowo_uptime_dashboard', routeParams: ['tenantSlug' => self::SLUG]),
            'page_sections'     => ['before' => [], 'after' => [['template' => 'host/_section.html.twig', 'context' => ['note' => 'Shared with Ana']]]],
        ] + $context;
    }

    /**
     * A monitor page with two check log pages and a host section before the bundle content.
     *
     * @return array<string, mixed>
     */
    public static function monitorShowPaged(): array
    {
        $context = self::monitorShow();

        return [
            'log_events'     => $context['detail']['events'],
            'log_pagination' => new Pagination(1, 1, 2, 2, 'nowo_uptime_monitor_show', routeParams: ['tenantSlug' => self::SLUG, 'id' => 7]),
            'page_sections'  => ['before' => [['template' => 'host/_section.html.twig', 'context' => ['note' => 'Shared with Ana']]], 'after' => []],
        ] + $context;
    }

    /**
     * Push monitor page: server health view with gauges, a disk and the three charts.
     *
     * @return array<string, mixed>
     */
    public static function monitorShowPush(): array
    {
        $series = ['hours' => 24, 'labels' => ['10:00'], 'cpu' => [12.5], 'memory' => [40.0], 'swap' => [null], 'load' => [0.4], 'disks' => ['/' => [71.0]]];

        return [
            'push' => [
                'url'       => 'https://uptime.test/push/abc',
                'has_token' => true,
                'signed'    => false,
                'last_at'   => null,
                'grace'     => 30,
                'gauges'    => [
                    'metrics'  => [['key' => 'cpu', 'value' => 12.5, 'level' => 'ok'], ['key' => 'load', 'value' => 0.4, 'level' => 'warn']],
                    'uptime_s' => 172800,
                    'disks'    => [['mount' => '/', 'used' => 71.0, 'inodes' => null, 'level' => 'critical']],
                ],
                'series'      => $series,
                'series_json' => json_encode($series, JSON_THROW_ON_ERROR),
                'ranges'      => [24, 168],
            ],
        ] + self::monitorShow();
    }

    /**
     * @return array<string, mixed>
     */
    public static function monitorShow(bool $group = false): array
    {
        $monitor = self::monitor(7, 'API health', $group ? MonitorType::Group : MonitorType::Https, 'https://example.com/health');
        $child   = self::monitor(8, 'Database', MonitorType::Tcp, 'db:3306');
        $latest  = $group ? null : self::check($monitor, CheckStatus::Down, 1200, 503, 'Service unavailable');

        return [
            'page' => new ShowPage(
                new PageHeader('API health', 'https://example.com/health', [
                    new Action('edit', UptimePages::label('monitor.edit'), '/main/monitors/7/edit', attributes: ['data-monitor-form-open' => true, 'data-monitor-form-url' => '/main/monitors/7/edit', 'data-monitor-form-return' => 'show']),
                ], back: new BackLink('/main', UptimePages::label('nav.dashboard'))),
                [new DetailSection(UptimePages::label('monitor.show.details'), [
                    new DetailItem(UptimePages::label('monitor.show.type'), $group ? 'group' : 'https', badge: 'neutral'),
                    new DetailItem(UptimePages::label('monitor.show.project'), null),
                    new DetailItem(UptimePages::label('monitor.show.target'), 'https://example.com/health'),
                    new DetailItem(UptimePages::label('monitor.show.interval_label'), UptimePages::label('monitor.show.seconds', ['%seconds%' => 60])),
                ], 'uptime-monitor-details')],
                bundle: UptimePages::BUNDLE,
                attributes: ['data-testid' => 'uptime-monitor'],
            ),
            'tenant_slug' => self::SLUG,
            'monitor'     => $monitor,
            'latest'      => $latest,
            'detail'      => [
                'history'        => [['status' => 'down', 'checked_at' => '2026-10-26T10:00:00+00:00']],
                'uptime_24h'     => $group ? null : 97.25,
                'uptime_30d'     => $group ? null : 99.1,
                'events'         => $group ? [] : [['status' => 'down', 'checked_at' => '2026-10-26T10:00:00+00:00', 'latency_ms' => 1200, 'status_code' => 503, 'message' => 'Service unavailable']],
                'latency_series' => ['labels' => [], 'latency_ms' => []],
                'children'       => $group ? [['monitor' => $child, 'latest' => self::check($child, CheckStatus::Up, 3)]] : [],
            ],
            'chart_series_json'   => '{"labels":[],"uptime":[],"latency":[]}',
            'latency_series_json' => '{"labels":[],"latency_ms":[]}',
            'api_history_url'     => '/main/api/monitors/7/history',
            'api_aggregates_url'  => '/main/api/monitors/7/aggregates',
        ] + self::shared();
    }

    /**
     * @return array<string, mixed>
     */
    public static function monitorForm(bool $edit = false): array
    {
        $form = self::forms()->create(MonitorFormType::class, new MonitorFormData(), ['group_choices' => ['Production' => 3]])->createView();

        return [
            'page' => new FormPage(
                new PageHeader(UptimePages::label($edit ? 'monitor.edit' : 'monitor.new'), back: new BackLink('/main', UptimePages::label('nav.dashboard'))),
                $form,
                UptimePages::label('monitor.form.save'),
                '/main',
                UptimePages::label('monitor.form.cancel'),
                bundle: UptimePages::BUNDLE,
                formThemes: self::formThemes(),
                formAttributes: ['class' => 'uptime-monitor-form'],
            ),
            'tenant_slug' => self::SLUG,
            'form'        => $form,
            'title'       => $edit ? 'monitor.edit' : 'monitor.new',
        ] + self::shared();
    }

    /**
     * Monitor dialog fragment (X-Uptime-Modal).
     *
     * @return array<string, mixed>
     */
    public static function monitorFormFragment(): array
    {
        return [
            'tenant_slug' => self::SLUG,
            'form'        => self::forms()->create(MonitorFormType::class, new MonitorFormData())->createView(),
        ] + self::shared();
    }

    /**
     * @param array<string, mixed> $vars
     *
     * @return array<string, mixed>
     */
    public static function settings(string $section, ?FormView $form = null, ?Label $submitLabel = null, array $vars = []): array
    {
        $sections = [];
        foreach (UptimeAreaNav::SETTINGS_SECTIONS as $id => $route) {
            $current    = $id === $section;
            $sections[] = new SettingsSection($id, UptimePages::label('settings.nav.' . $id), '/' . $route, $current ? $form : null, null, $current ? ($submitLabel ?? UptimePages::label('settings.save')) : null, self::formThemes());
        }

        return $vars + [
            'page' => new SettingsPage(
                new PageHeader(UptimePages::label('settings.title'), 'Main', back: new BackLink('/main', UptimePages::label('nav.dashboard'))),
                $sections,
                $section,
                UptimePages::BUNDLE,
                null,
                ['data-testid' => 'uptime-settings'],
            ),
            'tenant'              => self::tenant(),
            'tenant_slug'         => self::SLUG,
            'uptime_theme'        => 'auto',
            'uptime_search_index' => true,
            'section'             => $section,
            'section_title'       => 'settings.section.' . $section,
            'form'                => $form,
        ] + self::shared();
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public static function settingsPages(bool $emptyTags = false, bool $editing = false): array
    {
        $forms         = self::forms();
        $tags          = $emptyTags ? [] : [self::tag(1, 'production', '#22c55e'), self::tag(2, 'staging', '')];
        $deleteActions = [];
        $editActions   = [];
        foreach ($tags as $tag) {
            $editActions[(int) $tag->getId()]   = Action::edit('/main/settings/tags/' . $tag->getId(), UptimePages::label('settings.tags.edit'));
            $deleteActions[(int) $tag->getId()] = Action::delete(
                '/main/settings/tags/' . $tag->getId() . '/delete',
                null,
                new Confirm(UptimePages::label('settings.tags.delete_confirm_title'), UptimePages::label('settings.tags.delete_confirm', ['%name%' => $tag->getName()]), UptimePages::label('settings.tags.delete')),
                UptimePages::label('settings.tags.delete'),
                self::csrfForm('/main/settings/tags/' . $tag->getId() . '/delete', 'delete-tag-' . $tag->getId()),
            );
        }

        return [
            'general'       => self::settings('general', $forms->create(SettingsGeneralFormType::class, new SettingsGeneralData())->createView()),
            'appearance'    => self::settings('appearance', $forms->create(SettingsAppearanceFormType::class, new SettingsAppearanceData())->createView()),
            'notifications' => self::settings('notifications', null, null, [
                'notifications_config' => ['enabled' => true, 'cooldown_seconds' => 300],
                'notification_items'   => [
                    new DetailItem(UptimePages::label('settings.notifications.enabled'), UptimePages::label('settings.notifications.yes')),
                    new DetailItem(UptimePages::label('settings.notifications.cooldown'), UptimePages::label('settings.notifications.cooldown_seconds', ['%seconds%' => 300])),
                    new DetailItem(UptimePages::label('settings.notifications.email'), UptimePages::label('settings.notifications.no')),
                ],
            ]),
            'reverse_proxy' => self::settings('reverse_proxy', $forms->create(SettingsReverseProxyFormType::class, new SettingsReverseProxyData())->createView()),
            'status_page'   => self::settings('status_page', $forms->create(SettingsStatusPageFormType::class, new SettingsStatusPageData(), ['monitor_choices' => ['API health' => 7]])->createView(), null, [
                'status_page_url' => 'https://example.test/status/main',
                'has_monitors'    => true,
            ]),
            'tags' => self::settings('tags', $forms->create(TagFormType::class, new TagFormData())->createView(), UptimePages::label('settings.tags.add_button'), [
                'tags'               => $tags,
                'tag_delete_actions' => $deleteActions,
                'tag_edit_actions'   => $editActions,
                'editing_tag'        => $editing ? ($tags[0] ?? null) : null,
            ]),
            'history' => self::settings('history', $forms->create(SettingsHistoryFormType::class, new SettingsHistoryData())->createView(), null, [
                'global_detail_days' => 30,
                'history_actions'    => [
                    new Action('custom', UptimePages::label('settings.history.purge'), '/main/settings/history/purge', method: 'POST', variant: 'secondary', form: self::csrfForm('/main/settings/history/purge', 'purge-history')),
                    new Action('delete', UptimePages::label('settings.history.clear'), '/main/settings/history/clear-stats', method: 'POST', confirm: new Confirm(UptimePages::label('settings.history.clear'), UptimePages::label('settings.history.clear_confirm'), UptimePages::label('settings.history.clear')), form: self::csrfForm('/main/settings/history/clear-stats', 'clear-stats')),
                ],
            ]),
            'backup' => self::settings('backup', $forms->create(BackupImportFormType::class, null, ['action' => '/main/settings/backup'])->createView(), UptimePages::label('settings.backup.import_button'), [
                'export_action' => new Action('export', UptimePages::label('settings.backup.export_button'), '/main/settings/backup/export', variant: 'primary'),
            ]),
            'about' => self::settings('about', null, null, [
                'about_items' => [
                    new DetailItem(UptimePages::label('settings.about.product'), UptimePages::label('settings.about.product_value')),
                    new DetailItem(UptimePages::label('settings.about.tenant'), 'Main (main)'),
                ],
            ]),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function status(bool $empty = false): array
    {
        $monitor = self::monitor(7, 'API health', MonitorType::Https, 'https://example.com/health');
        $rows    = $empty ? [] : [['monitor' => $monitor, 'latest' => self::check($monitor, CheckStatus::Degraded, 900)]];

        return [
            'header'         => new PageHeader('Main', breadcrumbsMode: 'own'),
            'tenant'         => self::tenant(),
            'tenant_slug'    => self::SLUG,
            'rows'           => $rows,
            'overall_status' => $empty ? 'unknown' : 'degraded',
            'show_latency'   => true,
            'title'          => 'Main',
        ];
    }

    /**
     * The tenant status summary (since 1.13).
     *
     * @return array<string, mixed>
     */
    public static function summary(bool $empty = false): array
    {
        $api  = self::monitor(7, 'API health', MonitorType::Https, 'https://example.com/health');
        $db   = self::monitor(8, 'Database', MonitorType::Tcp, 'db:3306');
        $rows = $empty ? [] : [['monitor' => $api, 'latest' => self::check($api, CheckStatus::Down, 900)], ['monitor' => $db, 'latest' => null]];

        return [
            'page' => new DashboardPage(
                new PageHeader(UptimePages::label('summary.title'), null, $empty ? [] : [new Action('view', UptimePages::label('summary.public_page'), 'https://example.test/status/main', attributes: ['target' => '_blank', 'rel' => 'noopener'])]),
                bundle: UptimePages::BUNDLE,
                attributes: ['data-testid' => 'uptime-summary'],
            ),
            'tenant'         => self::tenant(),
            'tenant_slug'    => self::SLUG,
            'rows'           => $rows,
            'counts'         => $empty ? ['down' => 0, 'degraded' => 0, 'unknown' => 0, 'up' => 0] : ['down' => 1, 'degraded' => 0, 'unknown' => 1, 'up' => 0],
            'overall_status' => $empty ? 'unknown' : 'major',
            'public_url'     => $empty ? null : 'https://example.test/status/main',
            'summary_empty'  => new EmptyState(UptimePages::label('summary.empty_title'), UptimePages::label('summary.empty'), Action::view('/main', UptimePages::label('summary.go_monitors'))),
        ] + self::shared();
    }

    /**
     * Variables UptimePages::context() adds to every page.
     *
     * @return array<string, mixed>
     */
    private static function shared(): array
    {
        return ['nowo_uptime_legacy_layout' => null, 'uptime_form_themes' => self::formThemes()];
    }

    /**
     * @return list<string>
     */
    public static function formThemes(): array
    {
        return ['@NowoUiKitBundle/form/nowo_ui_layout.html.twig'];
    }

    public static function tenant(): Tenant
    {
        return new Tenant(self::SLUG, 'Main');
    }

    private static function monitor(int $id, string $name, MonitorType $type, string $target): Monitor
    {
        $monitor = new Monitor(self::tenant(), $name, $type, $target);
        (new ReflectionProperty($monitor, 'id'))->setValue($monitor, $id);

        return $monitor;
    }

    private static function tag(int $id, string $name, string $color): Tag
    {
        $tag = new Tag(self::tenant(), $name);
        if ($color !== '') {
            $tag->setColor($color);
        }
        (new ReflectionProperty($tag, 'id'))->setValue($tag, $id);

        return $tag;
    }

    private static function check(Monitor $monitor, CheckStatus $status, int $latencyMs, ?int $code = 200, ?string $message = null): CheckResult
    {
        $result = new CheckResult($monitor, $status, $latencyMs, $code, $message);
        (new ReflectionProperty($result, 'checkedAt'))->setValue($result, new DateTimeImmutable('2026-10-26 10:00:00'));

        return $result;
    }

    private static function csrfForm(string $action, string $tokenId): FormView
    {
        return self::forms()->create(CsrfActionFormType::class, null, ['action' => $action, 'csrf_token_id' => $tokenId])->createView();
    }

    /**
     * The production FormKit profile seeded by the extension (no framework classes, convention help / placeholder
     * keys only when translated), with the bundle catalogues.
     *
     * @template T of object
     *
     * @param T $formType
     *
     * @return T
     */
    private static function withMerger(object $formType): object
    {
        $translator = new Translator('en');
        $translator->addLoader('yaml', new YamlFileLoader());
        foreach (glob(dirname(__DIR__, 3) . '/src/Resources/translations/*.yaml') ?: [] as $file) {
            [$domain, $locale] = explode('.', basename($file));
            $translator->addResource('yaml', $file, $locale, $domain);
        }
        $profile = [
            'translation_domain'              => 'NowoUptimeMonitorBundle',
            'convention_requires_translation' => true,
            'defaults'                        => ['attr' => [], 'row_attr' => []],
            'field_types'                     => [],
        ];
        $merger = new FormOptionsMerger(['uptime_monitor' => $profile, 'default' => $profile], 'uptime_monitor', new ConstraintDefinitionFactory(), null, null, $translator);
        if (method_exists($formType, 'setFormOptionsMerger')) {
            $formType->setFormOptionsMerger($merger);
        }

        return $formType;
    }

    private static function forms(): FormFactoryInterface
    {
        return Forms::createFormFactoryBuilder()
            ->addExtension(new CsrfExtension(new FixedCsrfTokenManager()))
            ->addExtension(new ValidatorExtension(Validation::createValidator()))
            ->addType(self::withMerger(new MonitorFormType()))
            ->addType(self::withMerger(new TenantFormType()))
            ->addType(self::withMerger(new TagFormType()))
            ->addType(self::withMerger(new SettingsGeneralFormType()))
            ->addType(self::withMerger(new SettingsAppearanceFormType()))
            ->addType(self::withMerger(new SettingsHistoryFormType()))
            ->addType(self::withMerger(new SettingsReverseProxyFormType()))
            ->addType(self::withMerger(new SettingsStatusPageFormType()))
            ->addType(self::withMerger(new CsrfActionFormType()))
            ->addType(self::withMerger(new BackupImportFormType()))
            ->addType(self::withMerger(new MonitorSearchFormType()))
            ->getFormFactory();
    }
}
