<?php

declare(strict_types=1);

namespace Nowo\UptimeMonitorBundle\DependencyInjection;

use InvalidArgumentException;
use Symfony\Component\Config\Definition\Builder\ArrayNodeDefinition;
use Symfony\Component\Config\Definition\Builder\TreeBuilder;
use Symfony\Component\Config\Definition\ConfigurationInterface;

use function array_diff;
use function array_is_list;
use function array_keys;
use function implode;
use function is_array;
use function is_string;
use function preg_match;
use function sprintf;
use function str_contains;

/**
 * Configuration tree for {@see UptimeMonitorExtension}.
 */
final class Configuration implements ConfigurationInterface
{
    public const ALIAS = 'nowo_uptime_monitor';

    /** 1.x default of the deprecated templates.layout (the bundle no longer ships it). */
    public const LEGACY_LAYOUT = '@NowoUptimeMonitorBundle/layout.html.twig';

    /** Default form theme of the bundle forms (UiKit form theme: nowo-ui input / row slots). */
    public const DEFAULT_FORM_THEME = '@NowoUiKitBundle/form/nowo_ui_layout.html.twig';

    /** Dashboard layouts (`web_ui.dashboard.view`). */
    public const DASHBOARD_VIEWS = ['tree', 'table'];

    /** Dashboard header actions (`web_ui.dashboard.actions`), default order. */
    public const DASHBOARD_ACTIONS = ['settings', 'status_page', 'new_monitor'];

    /** Page ids accepted by web_ui.templates (REQ-UI-003 per-page override). */
    public const PAGES = [
        'dashboard',
        'monitor_show',
        'monitor_form',
        'settings_general',
        'settings_appearance',
        'settings_notifications',
        'settings_reverse_proxy',
        'settings_tags',
        'settings_history',
        'settings_backup',
        'settings_about',
        'tenant_index',
        'tenant_form',
        'status',
    ];

    private const PAGES_LIST = 'dashboard, monitor_show, monitor_form, settings_general, settings_appearance, settings_notifications, settings_reverse_proxy, settings_tags, settings_history, settings_backup, settings_about, tenant_index, tenant_form, status';

    /**
     * Builds the `nowo_uptime_monitor` configuration tree (with the deprecated 1.x keys and their replacements).
     */
    public function getConfigTreeBuilder(): TreeBuilder
    {
        $treeBuilder = new TreeBuilder(self::ALIAS);
        $root        = $treeBuilder->getRootNode();

        $root
            ->children()
                ->booleanNode('enabled')
                    ->info('Master switch for monitoring: false = no checks run (scheduler, nowo:uptime:run-due-checks and RunDueChecksMessage are no-ops). UI and data stay available.')
                    ->defaultTrue()
                ->end()
                ->arrayNode('environments')
                    ->info('Deprecated: never read. Use enabled (e.g. %env(bool:UPTIME_ENABLED)%) or register the bundle only for some environments in config/bundles.php.')
                    ->prototype('scalar')->end()
                    ->defaultValue(['prod', 'dev', 'test'])
                    ->setDeprecated('nowo-tech/uptime-monitor-bundle', '1.5', 'The "%path%.%node%" option is deprecated and has no effect: set "enabled" per environment or register the bundle only where needed in config/bundles.php.')
                ->end()
                ->scalarNode('connection')
                    ->info('Doctrine connection name for monitor storage')
                    ->defaultValue('default')
                ->end()
                ->scalarNode('table_prefix')
                    ->info('Deprecated: never read. Use doctrine.table_prefix to prefix the uptime_* tables.')
                    ->defaultValue('uptime_')
                    ->setDeprecated('nowo-tech/uptime-monitor-bundle', '1.5', 'The "%path%.%node%" option is deprecated and has no effect: use "nowo_uptime_monitor.doctrine.table_prefix" (prepended to the uptime_* table names) instead.')
                ->end()
                ->arrayNode('doctrine')
                    ->addDefaultsIfNotSet()
                    ->info('Doctrine storage options (REQ-ORM-002)')
                    ->children()
                        ->scalarNode('table_prefix')
                            ->info('Prefix prepended to every bundle table, index and unique constraint name (e.g. "app_" yields app_uptime_monitor). Empty = historical uptime_* names.')
                            ->defaultValue('')
                            ->example('app_')
                            ->validate()
                                ->ifTrue(static fn (mixed $v): bool => !is_string($v) || preg_match('/^[A-Za-z0-9_]*$/', $v) !== 1)
                                ->thenInvalid('nowo_uptime_monitor.doctrine.table_prefix may only contain letters, digits and underscores, got %s.')
                            ->end()
                        ->end()
                    ->end()
                ->end()
                ->arrayNode('scheduler')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->booleanNode('enabled')->defaultTrue()->end()
                        ->enumNode('mode')
                            ->values(['scheduler', 'cron', 'messenger'])
                            ->defaultValue('scheduler')
                        ->end()
                        ->scalarNode('tick')
                            ->info('How often the due-check scan runs (scheduler/cron)')
                            ->defaultValue('1 minute')
                        ->end()
                    ->end()
                ->end()
                ->arrayNode('checks')
                    ->addDefaultsIfNotSet()
                    ->info('Check execution and reported latency')
                    ->children()
                        ->integerNode('min_latency_ms')
                            ->info('Floor for stored latency (ms); 0 = no floor. Per-monitor override: config.min_latency_ms')
                            ->defaultValue(0)
                            ->min(0)
                        ->end()
                        ->booleanNode('block_private_urls')
                            ->info('Block HTTP/TCP/SSL/ping/DNS monitor targets (incl. redirects, proxies and private DNS answers) on private/local networks (SSRF mitigation).')
                            ->defaultTrue()
                        ->end()
                        ->floatNode('max_timeout_seconds')
                            ->info('Upper bound of every per-monitor timeout (HTTP, TCP, SSL; ping is capped at 30 s). One monitor can never stall the shared scan longer (SEC-004).')
                            ->defaultValue(60.0)
                            ->min(1.0)
                        ->end()
                        ->integerNode('max_body_bytes')
                            ->info('HTTP checks read the response body only when a keyword is configured, and at most this many bytes.')
                            ->defaultValue(1_048_576)
                            ->min(1)
                        ->end()
                        ->integerNode('max_redirects')
                            ->info('Upper bound of the per-monitor redirect limit of HTTP checks.')
                            ->defaultValue(20)
                            ->min(0)
                        ->end()
                        ->integerNode('max_scan_seconds')
                            ->info('Time budget of one due-check scan; monitors left over stay due for the next tick (oldest first). 0 = no budget.')
                            ->defaultValue(300)
                            ->min(0)
                        ->end()
                    ->end()
                ->end()
                ->arrayNode('retention')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->integerNode('detail_days')
                            ->info('Days to keep CheckResult detail rows; 0 = infinite (no purge)')
                            ->defaultValue(30)
                            ->min(0)
                        ->end()
                        ->booleanNode('purge_enabled')->defaultTrue()->end()
                        ->integerNode('purge_batch_size')
                            ->info('Rows deleted per keyset batch by the detail purge and nowo:uptime:clear-data (short autocommit statements instead of one unbounded DELETE)')
                            ->defaultValue(1000)
                            ->min(1)
                        ->end()
                    ->end()
                ->end()
                ->arrayNode('aggregates')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->booleanNode('keep_forever')
                            ->info('Deprecated: never read; aggregates are always kept (remove them with nowo:uptime:clear-data).')
                            ->defaultTrue()
                            ->setDeprecated('nowo-tech/uptime-monitor-bundle', '1.5', 'The "%path%.%node%" option is deprecated and has no effect: aggregates are always kept; use nowo:uptime:clear-data to remove them.')
                        ->end()
                        ->arrayNode('periods')
                            ->prototype('scalar')->end()
                            ->defaultValue(['hour', 'day', 'month', 'year'])
                        ->end()
                    ->end()
                ->end()
                ->arrayNode('multi_tenant')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->booleanNode('enabled')
                            ->info('Deprecated: never read; tenants are always resolved from the URL.')
                            ->defaultTrue()
                            ->setDeprecated('nowo-tech/uptime-monitor-bundle', '1.5', 'The "%path%.%node%" option is deprecated and has no effect: tenants are always resolved from the URL slug (use tenants.list_enabled / redirect_when_single for single-tenant apps).')
                        ->end()
                        ->scalarNode('header')
                            ->info('Deprecated: never read; tenants are resolved from the URL, not a request header.')
                            ->defaultValue('X-Uptime-Tenant')
                            ->setDeprecated('nowo-tech/uptime-monitor-bundle', '1.5', 'The "%path%.%node%" option is deprecated and has no effect: tenants are resolved from the URL slug, not a request header.')
                        ->end()
                        ->scalarNode('default_tenant')->defaultValue('main')->end()
                    ->end()
                ->end()
                ->arrayNode('tenants')
                    ->addDefaultsIfNotSet()
                    ->info('Tenant list UI (/tenants). Disable in single-tenant host apps (e.g. nowo-devkit).')
                    ->children()
                        ->booleanNode('list_enabled')
                            ->defaultTrue()
                            ->info('When false, GET /tenants redirects to the default tenant dashboard.')
                        ->end()
                        ->booleanNode('redirect_when_single')
                            ->defaultFalse()
                            ->info('When true and exactly one tenant exists, /tenants redirects to its dashboard.')
                        ->end()
                    ->end()
                ->end()
                ->arrayNode('security')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->arrayNode('access_roles')
                            ->scalarPrototype()->end()
                            ->defaultValue(['ROLE_ADMIN'])
                            ->info('General gate: user must have at least one role. Empty = the area roles alone decide; an area whose list is empty too denies everyone. Every list empty with the web UI on fails the container compile.')
                        ->end()
                        ->scalarNode('access_checker')
                            ->defaultNull()
                            ->info('Optional service id implementing UptimeMonitorAccessCheckerInterface. null = built-in role checker.')
                        ->end()
                        ->booleanNode('allow_unauthenticated')
                            ->defaultFalse()
                            ->info('DEV/DEMO only: skip bundle access checks and allow UI without SecurityBundle. Production MUST keep false.')
                        ->end()
                        ->arrayNode('roles')
                            ->addDefaultsIfNotSet()
                            ->info('Extra roles per action (REQ-UI-003 security.roles.<action>; replace dashboard_roles / manage_roles / settings_roles). null = the deprecated key (default [ROLE_ADMIN]).')
                            ->children()
                                ->variableNode('dashboard')
                                    ->info('Dashboard / read / polling API. Empty = allow all who pass access_roles (deny everyone when access_roles is empty too). List of roles.')
                                    ->defaultNull()
                                    ->validate()
                                        ->ifTrue(static fn (mixed $v): bool => $v !== null && !self::isRoleList($v))
                                        ->thenInvalid('Expected a list of role names, got %s.')
                                    ->end()
                                ->end()
                                ->variableNode('manage')
                                    ->info('Monitor CRUD mutations. List of roles.')
                                    ->defaultNull()
                                    ->validate()
                                        ->ifTrue(static fn (mixed $v): bool => $v !== null && !self::isRoleList($v))
                                        ->thenInvalid('Expected a list of role names, got %s.')
                                    ->end()
                                ->end()
                                ->variableNode('settings')
                                    ->info('Tenant settings, tenant creation and unmapped unsafe-method admin actions. List of roles.')
                                    ->defaultNull()
                                    ->validate()
                                        ->ifTrue(static fn (mixed $v): bool => $v !== null && !self::isRoleList($v))
                                        ->thenInvalid('Expected a list of role names, got %s.')
                                    ->end()
                                ->end()
                            ->end()
                        ->end()
                        ->arrayNode('dashboard_roles')
                            ->scalarPrototype()->end()
                            ->defaultValue(['ROLE_ADMIN'])
                            ->info('Deprecated: use security.roles.dashboard.')
                            ->setDeprecated('nowo-tech/uptime-monitor-bundle', '1.11', 'The "%path%.%node%" option is deprecated: use "nowo_uptime_monitor.security.roles.dashboard".')
                        ->end()
                        ->arrayNode('manage_roles')
                            ->scalarPrototype()->end()
                            ->defaultValue(['ROLE_ADMIN'])
                            ->info('Deprecated: use security.roles.manage.')
                            ->setDeprecated('nowo-tech/uptime-monitor-bundle', '1.11', 'The "%path%.%node%" option is deprecated: use "nowo_uptime_monitor.security.roles.manage".')
                        ->end()
                        ->arrayNode('settings_roles')
                            ->scalarPrototype()->end()
                            ->defaultValue(['ROLE_ADMIN'])
                            ->info('Deprecated: use security.roles.settings.')
                            ->setDeprecated('nowo-tech/uptime-monitor-bundle', '1.11', 'The "%path%.%node%" option is deprecated: use "nowo_uptime_monitor.security.roles.settings".')
                        ->end()
                    ->end()
                ->end()
                ->arrayNode('web_ui')
                    ->addDefaultsIfNotSet()
                    ->info('UiKit page contract (REQ-UI-003): pages extend UiKit scaffolds rendered in nowo_ui_kit.layouts.admin / .guest.')
                    ->children()
                        ->booleanNode('enabled')
                            ->defaultNull()
                            ->info('Admin UI switch (REQ-UI-003 canonical key; replaces dashboard.enabled). null = dashboard.enabled (default true).')
                        ->end()
                        ->scalarNode('path_prefix')
                            ->defaultNull()
                            ->info('URL prefix of every admin route, may hold {tenantSlug} (REQ-UI-003 canonical key; replaces dashboard.path). null = dashboard.path (default /uptime).')
                        ->end()
                        ->append($this->tenantPathsNode(false))
                        ->scalarNode('layout_template')
                            ->defaultNull()
                            ->info('Admin layout of the bundle pages; null (default) = nowo_ui_kit.layouts.admin. The layout renders the UiKit canonical blocks (nowo_ui_content…).')
                        ->end()
                        ->arrayNode('templates')
                            ->info('Per-page template override: page => template (a thin extends of the bundle page + data block override). Pages: ' . self::PAGES_LIST . '.')
                            ->useAttributeAsKey('page')
                            ->scalarPrototype()->end()
                            ->defaultValue([])
                            ->validate()
                                ->ifTrue(static fn (array $v): bool => array_diff(array_keys($v), self::PAGES) !== [])
                                ->then(static function (array $v): array {
                                    throw new InvalidArgumentException(sprintf('Unknown nowo_uptime_monitor.web_ui.templates page(s) "%s"; known pages: %s.', implode('", "', array_diff(array_keys($v), self::PAGES)), self::PAGES_LIST));
                                })
                            ->end()
                        ->end()
                        ->arrayNode('form_themes')
                            ->info('Form themes of the bundle forms (UiKit FormPage / SettingsSection formThemes). Empty = only the host twig.form_themes.')
                            ->scalarPrototype()->end()
                            ->defaultValue([self::DEFAULT_FORM_THEME])
                        ->end()
                        ->arrayNode('dashboard')
                            ->addDefaultsIfNotSet()
                            ->info('Tenant dashboard page.')
                            ->children()
                                ->enumNode('view')
                                    ->values(self::DASHBOARD_VIEWS)
                                    ->defaultValue('tree')
                                    ->info('tree: the live monitor tree with quick stats and recent checks; table: a monitors table (status, 24 h uptime, last check, interval, row actions) and a paginated card of the latest checks — for hosts that embed the pages in their own navigation.')
                                ->end()
                                ->enumNode('header')
                                    ->values(['tenant', 'monitors'])
                                    ->defaultValue('tenant')
                                    ->info('Page header title: tenant (its name, the slug as intro) or monitors ("Monitors" — for hosts that already show the tenant around the page; the table view then drops its card title).')
                                ->end()
                                ->arrayNode('actions')
                                    ->info('Page header actions, in this order (each one only for users allowed to use it): ' . implode(', ', self::DASHBOARD_ACTIONS) . '.')
                                    ->enumPrototype()->values(self::DASHBOARD_ACTIONS)->end()
                                    ->defaultValue(self::DASHBOARD_ACTIONS)
                                ->end()
                            ->end()
                        ->end()
                        ->integerNode('per_page')
                            ->min(5)
                            ->max(200)
                            ->defaultValue(20)
                            ->info('Rows per page of the check log (monitor page) and of the latest checks (table dashboard).')
                        ->end()
                    ->end()
                ->end()
                ->arrayNode('limits')
                    ->addDefaultsIfNotSet()
                    ->info('Per-tenant monitor limits: the New monitor action is disabled and the form refuses a monitor beyond them.')
                    ->children()
                        ->scalarNode('provider')
                            ->defaultNull()
                            ->info('Service id of a Nowo\\UptimeMonitorBundle\\Limits\\TenantLimitsInterface deciding the limits per tenant; null = max_monitors / min_interval_seconds for every tenant.')
                        ->end()
                        ->integerNode('max_monitors')
                            ->min(0)
                            ->defaultValue(0)
                            ->info('Monitors per tenant, groups not counted (0 = no limit).')
                        ->end()
                        ->integerNode('min_interval_seconds')
                            ->min(1)
                            ->defaultValue(30)
                            ->info('Shortest check interval of a monitor, in seconds.')
                        ->end()
                    ->end()
                ->end()
                ->arrayNode('form')
                    ->addDefaultsIfNotSet()
                    ->info('Monitor form options.')
                    ->children()
                        ->arrayNode('hidden_fields')
                            ->info('MonitorFormType fields left out of the form (e.g. project, resendNotificationAfterDown); a monitor keeps their stored values.')
                            ->scalarPrototype()->end()
                            ->defaultValue([])
                        ->end()
                    ->end()
                ->end()
                ->arrayNode('templates')
                    ->addDefaultsIfNotSet()
                    ->info('Deprecated since 1.11: use web_ui.layout_template (null = UiKit layout). A 1.x layout (block uptime_content) set here is wrapped by a BC bridge.')
                    ->beforeNormalization()
                        ->ifArray()
                        ->then(static function (array $v): array {
                            // BC alias: layout_template → layout (REQ-UI-001 naming)
                            if (isset($v['layout_template']) && !isset($v['layout'])) {
                                $v['layout'] = $v['layout_template'];
                            }
                            unset($v['layout_template']);

                            return $v;
                        })
                    ->end()
                    ->children()
                        ->scalarNode('layout')
                            ->defaultValue(self::LEGACY_LAYOUT)
                            ->info('Deprecated: 1.x layout rendering {% block uptime_content %}, wrapped by @NowoUptimeMonitorBundle/_legacy_layout.html.twig. Alias: templates.layout_template.')
                            ->setDeprecated('nowo-tech/uptime-monitor-bundle', '1.11', 'The "%path%.%node%" option is deprecated: set "nowo_uptime_monitor.web_ui.layout_template" to a layout that renders the UiKit canonical blocks (null = nowo_ui_kit.layouts.admin). A 1.x layout set here is wrapped by a BC bridge until 2.0.')
                        ->end()
                    ->end()
                ->end()
                ->arrayNode('ui')
                    ->addDefaultsIfNotSet()
                    ->info('Deprecated since 1.11: the pages follow nowo_ui_kit (css_framework, layouts, theme). ui.framework only seeds nowo_ui_kit.css_framework when the host does not set it; the CDN keys are never read.')
                    ->beforeNormalization()
                        ->ifArray()
                        ->then(static function (array $v): array {
                            // BC alias: css_framework → framework (REQ-UI-001 naming)
                            if (isset($v['css_framework']) && !isset($v['framework'])) {
                                $v['framework'] = $v['css_framework'];
                            }
                            unset($v['css_framework']);

                            return $v;
                        })
                    ->end()
                    ->children()
                        ->enumNode('framework')
                            ->values(['tabler', 'custom', 'bootstrap', 'tailwind'])
                            ->defaultValue('tabler')
                            ->info('Seeds nowo_ui_kit.css_framework (bootstrap = bootstrap5) when the host does not set it. Alias: ui.css_framework.')
                            ->setDeprecated('nowo-tech/uptime-monitor-bundle', '1.11', 'The "%path%.%node%" option is deprecated: set "nowo_ui_kit.css_framework" (the bundle pages are UiKit scaffolds).')
                        ->end()
                        ->arrayNode('tabler')
                            ->addDefaultsIfNotSet()
                            ->info('Deprecated, never read: the bundle loads no CSS framework (the host layout owns it).')
                            ->children()
                                ->booleanNode('skip_cdn')
                                    ->defaultFalse()
                                    ->setDeprecated('nowo-tech/uptime-monitor-bundle', '1.11', 'The "%path%.%node%" option is deprecated and has no effect: the bundle no longer loads Tabler from a CDN.')
                                ->end()
                            ->end()
                        ->end()
                        ->arrayNode('bootstrap')
                            ->addDefaultsIfNotSet()
                            ->children()
                                ->scalarNode('css_url')
                                    ->defaultValue('https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css')
                                    ->setDeprecated('nowo-tech/uptime-monitor-bundle', '1.11', 'The "%path%.%node%" option is deprecated and has no effect: the bundle no longer loads Bootstrap from a CDN.')
                                ->end()
                                ->scalarNode('js_url')
                                    ->defaultValue('https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js')
                                    ->setDeprecated('nowo-tech/uptime-monitor-bundle', '1.11', 'The "%path%.%node%" option is deprecated and has no effect: the bundle no longer loads Bootstrap from a CDN.')
                                ->end()
                            ->end()
                        ->end()
                        ->arrayNode('tailwind')
                            ->addDefaultsIfNotSet()
                            ->children()
                                ->scalarNode('cdn_script')
                                    ->defaultValue('https://cdn.tailwindcss.com')
                                    ->setDeprecated('nowo-tech/uptime-monitor-bundle', '1.11', 'The "%path%.%node%" option is deprecated and has no effect: the bundle no longer loads Tailwind from a CDN.')
                                ->end()
                                ->scalarNode('css_url')
                                    ->defaultNull()
                                    ->setDeprecated('nowo-tech/uptime-monitor-bundle', '1.11', 'The "%path%.%node%" option is deprecated and has no effect: the host layout loads its CSS.')
                                    ->info('Optional compiled Tailwind CSS URL (overrides CDN script when set)')
                                ->end()
                            ->end()
                        ->end()
                    ->end()
                ->end()
                ->arrayNode('dashboard')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->booleanNode('enabled')
                            ->defaultTrue()
                            ->setDeprecated('nowo-tech/uptime-monitor-bundle', '1.11', 'The "%path%.%node%" option is deprecated: use "nowo_uptime_monitor.web_ui.enabled".')
                        ->end()
                        ->scalarNode('path')
                            ->defaultValue('/uptime')
                            ->setDeprecated('nowo-tech/uptime-monitor-bundle', '1.11', 'The "%path%.%node%" option is deprecated: use "nowo_uptime_monitor.web_ui.path_prefix".')
                        ->end()
                        ->scalarNode('prefix')
                            ->info('Deprecated: never read; dashboard.path is the URL prefix of every admin route.')
                            ->defaultValue('')
                            ->setDeprecated('nowo-tech/uptime-monitor-bundle', '1.5', 'The "%path%.%node%" option is deprecated and has no effect: use "dashboard.path" as the admin URL prefix.')
                        ->end()
                        ->arrayNode('roles')
                            ->info('Deprecated: never read; use security.dashboard_roles.')
                            ->prototype('scalar')->end()
                            ->defaultValue(['ROLE_ADMIN'])
                            ->setDeprecated('nowo-tech/uptime-monitor-bundle', '1.5', 'The "%path%.%node%" option is deprecated and has no effect: use "security.dashboard_roles".')
                        ->end()
                        ->enumNode('sync')
                            ->values(['polling', 'mercure'])
                            ->defaultValue('polling')
                        ->end()
                        ->integerNode('poll_interval_ms')->defaultValue(30000)->min(1000)->end()
                        ->append($this->tenantPathsNode(true))
                        ->arrayNode('mercure')
                            ->addDefaultsIfNotSet()
                            ->info('Mercure topics when dashboard.sync is mercure (requires symfony/mercure-bundle)')
                            ->children()
                                ->scalarNode('topic_template')
                                    ->defaultValue('/uptime/{tenant}')
                                    ->info('Topic per tenant; {tenant} is replaced with tenant slug')
                                ->end()
                                ->booleanNode('private')->defaultTrue()->end()
                            ->end()
                        ->end()
                    ->end()
                ->end()
                ->arrayNode('status_page')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->booleanNode('enabled')->defaultTrue()->end()
                        ->booleanNode('default_public')
                            ->info('Whether a tenant status page is public when the tenant did not choose (Settings → General → Public status page). true keeps the 1.x behaviour; set false to make status pages opt-in.')
                            ->defaultTrue()
                        ->end()
                        ->scalarNode('path')->defaultValue('/status')->end()
                        ->scalarNode('title')->defaultNull()->info('Override page title; defaults to tenant name')->end()
                        ->booleanNode('show_latency')->defaultTrue()->end()
                    ->end()
                ->end()
                ->arrayNode('notifications')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->booleanNode('enabled')->defaultFalse()->end()
                        ->integerNode('cooldown_seconds')->defaultValue(300)->min(0)->end()
                        ->floatNode('http_timeout')
                            ->info('Webhook / Slack delivery deadline in seconds (HttpClient timeout + max_duration). Keep it below the PHP / worker time limits (REQ-RUNTIME-001).')
                            ->defaultValue(10.0)
                            ->min(1.0)
                        ->end()
                        ->arrayNode('email')
                            ->addDefaultsIfNotSet()
                            ->children()
                                ->booleanNode('enabled')->defaultFalse()->end()
                                ->scalarNode('from')->defaultValue('')->end()
                                ->arrayNode('to')->prototype('scalar')->end()->defaultValue([])->end()
                            ->end()
                        ->end()
                        ->arrayNode('webhook')
                            ->addDefaultsIfNotSet()
                            ->children()
                                ->booleanNode('enabled')->defaultFalse()->end()
                                ->scalarNode('url')->defaultValue('')->end()
                            ->end()
                        ->end()
                        ->arrayNode('slack')
                            ->addDefaultsIfNotSet()
                            ->children()
                                ->booleanNode('enabled')->defaultFalse()->end()
                                ->scalarNode('webhook_url')->defaultValue('')->end()
                            ->end()
                        ->end()
                    ->end()
                ->end()
            ->end();

        return $treeBuilder;
    }

    /**
     * `web_ui.tenant_paths` (canonical) / `dashboard.tenant_paths` (deprecated alias): more URL prefixes for the tenant
     * pages, chosen by tenant slug prefix.
     */
    private function tenantPathsNode(bool $deprecated): ArrayNodeDefinition
    {
        $node = (new TreeBuilder('tenant_paths'))->getRootNode();
        $node
            ->info('More URL prefixes for the tenant pages, chosen by tenant slug prefix (e.g. personal tenants "user-…" under /uptime/{tenantSlug}): each copies the tenant routes as <name_prefix><route>; links to a matching slug use them.' . ($deprecated ? ' Deprecated: use web_ui.tenant_paths.' : ''))
            ->arrayPrototype()
                ->children()
                    ->scalarNode('slug_prefix')->isRequired()->cannotBeEmpty()->end()
                    ->scalarNode('path')
                        ->isRequired()
                        ->cannotBeEmpty()
                        ->validate()
                            ->ifTrue(static fn (string $path): bool => !str_contains($path, '{tenantSlug}'))
                            ->thenInvalid('A tenant path needs the {tenantSlug} placeholder: %s')
                        ->end()
                    ->end()
                    ->scalarNode('name_prefix')->isRequired()->cannotBeEmpty()->end()
                    ->scalarNode('slug_requirement')->defaultNull()->info('Route requirement of {tenantSlug}; default: the slug prefix then [a-z0-9-]+.')->end()
                ->end()
            ->end()
            ->defaultValue([]);
        if ($deprecated) {
            $node->setDeprecated('nowo-tech/uptime-monitor-bundle', '1.11', 'The "%path%.%node%" option is deprecated: use "nowo_uptime_monitor.web_ui.tenant_paths".');
        }

        return $node;
    }

    /**
     * Whether a security.roles.<action> value is a list of role names.
     */
    private static function isRoleList(mixed $value): bool
    {
        if (!is_array($value) || !array_is_list($value)) {
            return false;
        }

        foreach ($value as $role) {
            if (!is_string($role) || $role === '') {
                return false;
            }
        }

        return true;
    }
}
