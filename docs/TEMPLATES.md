# Templates

Pages of UptimeMonitorBundle on the UiKit page contract (REQ-UI-003, UiKit 2.15). The controllers build the UiKit
view models (`Nowo\UiKitBundle\View\*`); `Nowo\UptimeMonitorBundle\Ui\UptimePages` resolves the layout
(`web_ui.layout_template`, null = `nowo_ui_kit.layouts.admin`), the per-page overrides (`web_ui.templates.<page>`)
and the form themes (`web_ui.form_themes`). Templates extend a UiKit scaffold (or, for the public status page, the
guest layout) and fill data blocks only. Contract test: `tests/Integration/Contract/PageContractTest.php` (UiKit
`PageContractTestCase`).

## Table of contents

- [Pages](#pages)
- [Shared page context](#shared-page-context)
- [Unique pages](#unique-pages)
- [Per-page overrides](#per-page-overrides)
- [Bundle partials](#bundle-partials)
- [Navigation](#navigation)
- [Allowed exceptions](#allowed-exceptions)

## Pages

Every view model passes `bundle: 'NowoUptimeMonitorBundle'` (theme exception
`nowo_ui_kit.theme.bundles.NowoUptimeMonitorBundle`) and `layout: UptimePages::layout()`. Labels are
`NowoUptimeMonitorBundle` translation keys (`UptimePages::label()`).

| Page (`web_ui.templates` id) | Template | Scaffold | View model | Data blocks filled |
| --- | --- | --- | --- | --- |
| Tenants (`tenant_index`) | `tenant/index.html.twig` | `page/list` | `ListPage` (header `tenant.index.title`, action New tenant; columns name, slug; rows = `Tenant`; `EmptyState` `tenant.index.empty`; `data-testid="uptime-tenants"`) | `nowo_ui_list_row` (name → tenant dashboard, slug in the `code` slot) |
| New tenant (`tenant_form`) | `tenant/form.html.twig` | `page/form` | `FormPage` (header `tenant.new.title`, back link to the tenants; `TenantFormType`; submit `tenant.form.create`; cancel = tenants; `formThemes` = `web_ui.form_themes`) | — (scaffold defaults: REQ-TWIG-003 child loop) |
| Dashboard (`dashboard`) | `dashboard/index.html.twig` | `page/dashboard` | `DashboardPage` (header = tenant name, intro = slug when it differs; actions Settings, Status page, New monitor — the last one is a link to the form page that `uptime-dashboard.js` opens in the monitor dialog; `data-testid="uptime-dashboard"`) | `nowo_ui_dashboard_cards` (live Kuma layout: monitor search + tree, quick stats on the `kpis` / `kpi` slots, recent checks table on the `card` / `table` slots), `nowo_ui_modals` (monitor dialog), `nowo_ui_document_title` (`dashboard.title`) |
| Monitor detail (`monitor_show`) | `monitor/show.html.twig` | `page/show` | `ShowPage` (header = monitor name, intro = target, back link to the dashboard, action Edit (monitor dialog / form page); section `uptime-monitor-details`: type, group or project, target, interval; `data-testid="uptime-monitor"`) | `nowo_ui_show_sections` (the UiKit detail cards; the first card header carries the status badge — `uptime-status-badge uptime-status-badge--<status>` plus the UiKit `badge_*` slot of the status, `data-detail-status`, `data-status-badges` = translated label and classes of every status — since 1.17), `nowo_ui_show_after` (check history bar, uptime / latency KPIs, charts, check log, group children, push health — sibling cards, no card around them; updated by `uptime-monitor-detail.js`), `nowo_ui_modals` (monitor dialog) |
| Monitor create / edit (`monitor_form`) | `monitor/form.html.twig` | `page/form` | `FormPage` (header `monitor.new` / `monitor.edit`, back link to the dashboard; `MonitorFormType`; submit `monitor.form.save`, cancel `monitor.form.cancel` = dashboard; `formAttributes.class` `uptime-monitor-form`; `formThemes` = `web_ui.form_themes`) | `nowo_ui_form_body` (fieldsets per monitor type — `data-monitor-fields`, toggled by `uptime-dashboard.js` — then the REQ-TWIG-003 child loop), `nowo_ui_form_aside` (group monitors hint) |
| Settings → General (`settings_general`) | `settings/general.html.twig` | `page/settings` | `SettingsPage` (see [Settings pages](#settings-pages)); section form `SettingsGeneralFormType` | `nowo_ui_settings_section` (fields grouped in three fieldsets, the REQ-TWIG-003 child loop, submit row) |
| Settings → Appearance (`settings_appearance`) | `settings/appearance.html.twig` | `page/settings` | `SettingsPage`; section form `SettingsAppearanceFormType` | `nowo_ui_settings_section` (interface language notice, then `parent()`) |
| Settings → Notifications (`settings_notifications`) | `settings/notifications.html.twig` | `page/settings` | `SettingsPage` (section without form); `notification_items` (`list<DetailItem>`) | `nowo_ui_settings_section` (notes + the `detail_list` partial) |
| Settings → Reverse proxy (`settings_reverse_proxy`) | `settings/section.html.twig` | `page/settings` | `SettingsPage`; section form `SettingsReverseProxyFormType` | `nowo_ui_settings_section` (`parent()`) |
| Settings → Status page (`settings_status_page`, 1.13) | `settings/status_page.html.twig` | `page/settings` | `SettingsPage`; section form `SettingsStatusPageFormType` (visibility, monitors shown — none: all); `status_page_url` (the public page when reachable), `has_monitors` | `nowo_ui_settings_section` (link to the public page or why it is not reachable, then `parent()`) |
| Settings → Tags (`settings_tags`) | `settings/tags.html.twig` | `page/settings` | `SettingsPage`; section form `TagFormType` (submit `settings.tags.add_button`); `tags`, `tag_delete_actions` (tag id => `Action::delete` with a CSRF-only `FormView` and a `Confirm`) | `nowo_ui_settings_section` (tag list + delete actions through the `action` partial, `empty` partial when there are none, then `parent()`) |
| Settings → Monitor history (`settings_history`) | `settings/history.html.twig` | `page/settings` | `SettingsPage`; section form `SettingsHistoryFormType`; `history_actions` (Purge expired detail rows: POST + CSRF `FormView`; Clear all statistics: POST + CSRF `FormView` behind a `Confirm`) | `nowo_ui_settings_section` (`parent()`, retention note, actions through the `action` partial) |
| Settings → Backup (`settings_backup`) | `settings/backup.html.twig` | `page/settings` | `SettingsPage`; section form `BackupImportFormType` (multipart, submit `settings.backup.import_button`); `export_action` (GET `Action`) | `nowo_ui_settings_section` (export block, then `parent()` for the import form) |
| Settings → About (`settings_about`) | `settings/about.html.twig` | `page/settings` | `SettingsPage` (section without form); `about_items` (`list<DetailItem>`) | `nowo_ui_settings_section` (`detail_list` partial + note) |
| Status summary (`summary`, 1.13) | `summary/index.html.twig` | `page/dashboard` | `DashboardPage` (header `summary.title`, action Public status page when the tenant's page is public; `data-testid="uptime-summary"`); `rows` (active monitors + latest check), `counts`, `overall_status`, `public_url`, `summary_empty` (`EmptyState` with the way to the monitors) | `nowo_ui_dashboard_cards` (n of m operational, the others by status, the monitors linking to their page) |

### Settings pages

`SettingsController::renderSettings()` builds one `SettingsSection` per screen the host keeps (`web_ui.settings.sections`,
since 1.13; default all of `UptimeAreaNav::SETTINGS_SECTIONS`: general, appearance, notifications, reverse_proxy,
status_page, tags, history, backup, about — the others answer 404) with its href, so the scaffold
renders the section tabs; `current` is the screen and only that section carries the form (`formThemes` =
`web_ui.form_themes`). Header: `settings.title`, intro = tenant name, back link to the dashboard,
`data-testid="uptime-settings"`. Each section body is wrapped in `data-testid="uptime-settings-<section>"`.

## Shared page context

`UptimePages::context()` adds to every admin page:

| Variable | Content |
| --- | --- |
| `nowo_uptime_legacy_layout` | The 1.x layout wrapped by the legacy bridge (deprecated `templates.layout`), else null |
| `uptime_form_themes` | `web_ui.form_themes` (used by the monitor dialog fragment) |

1.x variables kept for host templates set through `web_ui.templates.<page>`: dashboard `tenant`, `tenant_slug`,
`groups`, `stats`, `events`, `events_filter_monitor(_id)`, `dashboard_sync`, `poll_interval_ms`, `api_summary_url`,
`layout_fragment_url`, `mercure_hub_url`, `mercure_topic`, `heartbeat_bar_theme`, `elapsed_time_display`,
`monitor_search_form`, `uptime_theme`, `uptime_search_index`; monitor pages `tenant_slug`, `monitor`, `latest`,
`detail`, `chart_series_json`, `latency_series_json`, `api_history_url`, `api_aggregates_url`, `form`, `title`;
settings `tenant`, `tenant_slug`, `section`, `section_title`, `form`, `tags`, `tag_delete_forms`,
`notifications_config`, `purge_form`, `clear_form`, `global_detail_days`; tenants `tenants`, `form`, `title`.

Bundle-specific wrappers inside the data blocks carry `uptime-app uptime-app--embedded uptime-theme-<theme>` (and
`data-theme="light|dark"` when the tenant forces a theme in Settings → Appearance), so the bundle stylesheet
(`uptime-dashboard.css`, `uptime-theme.css`, stacked in `nowo_ui_styles` after `{{ parent() }}`) maps its widget
colours to the UiKit `--nowo-ui-*` tokens.

### Flat sections and chart colours (1.17)

- **No card in a card.** The monitor form fieldsets (`.uptime-form-section`) are flat inside the form card: the legend
  is the section heading and a rule separates the visible sections. To get the 1.16 tinted cards back, add the class
  `uptime-form-sections--cards` to an ancestor of the form (or to the `.uptime-app` wrapper of an overriding
  template). On the monitor page `.uptime-detail` is no longer drawn as a card around the history, figures, charts and
  check log; the check log and the push disk table scroll in a plain `.uptime-table-scroll` (not the `table_wrap`
  slot, which a host may theme as a panel); the push health charts and gauges sit flat inside the health card.
- **Chart colours.** `uptime-monitor-detail.js` reads these custom properties from the page root
  (`#uptime-monitor-detail-root`, inside `.uptime-app`); the bundle stylesheet sets them on `.uptime-app` from the UiKit
  tokens, the script falls back to the same colours when one is empty. Override them on `.uptime-app` or any ancestor:

  | Property | Default | Used by |
  | --- | --- | --- |
  | `--uptime-chart-uptime` | `var(--nowo-ui-success-text, #16a34a)` | uptime chart (line; area at 25 %) |
  | `--uptime-chart-latency` | `var(--nowo-ui-primary, #2563eb)` | response time and latency charts |
  | `--uptime-chart-grid` | `var(--nowo-ui-border, #e2e8f0)` | grid lines |
  | `--uptime-chart-text` | `var(--nowo-ui-muted, #64748b)` | tick and legend labels |
  | `--uptime-chart-series-1` … `-6` | primary, success, warning, `#9333ea`, danger, info | push health series (CPU, memory, swap, load, disks) |

## Unique pages

- `status/index.html.twig` — public status page (`status_page.path`, not gated). Extends `nowo_ui_layout.guest`
  directly (public front page: host design, no admin scaffold), sets `nowo_ui_bundle`, fills
  `nowo_ui_document_title` (title — `status_page.title_suffix`), `nowo_ui_styles` (bundle CSS after `parent()`),
  `nowo_ui_page_header` (`nowo_ui_page_header(header)`: `PageHeader` with the status page title, breadcrumbs mode
  `own` without crumbs) and `nowo_ui_content` (overall status badge, services list with `badge_*` / `card` /
  `muted` slots, `empty` partial when there is no monitor; `data-testid="uptime-status-page"`). No script.
  Variables: `header`, `tenant`, `tenant_slug`, `rows`, `overall_status`, `show_latency`, `title`.

## Per-page overrides

- `nowo_uptime_monitor.web_ui.templates.<page>` (ids in the [Pages](#pages) table): a host template that extends
  `@NowoUptimeMonitorBundle/<template>` and overrides a data block (`nowo_ui_list_row`, `nowo_ui_list_after`,
  `nowo_ui_dashboard_cards`, `nowo_ui_show_after`, `nowo_ui_form_body`, `nowo_ui_form_aside`,
  `nowo_ui_settings_section`, …). Prefer theming the UiKit slots (`nowo_ui_kit.theme`) and scaffolds once for every
  bundle.
- `templates/bundles/NowoUptimeMonitorBundle/<subpath>` still overrides any bundle template (REQ-TWIG-001), but
  freezes it: prefer `web_ui.templates`.

## Bundle partials

| Partial | Role |
| --- | --- |
| `_stylesheets.html.twig` | Bundle CSS (`uptime-dashboard.css`, `uptime-theme.css`, package `nowo_uptime_monitor`); no CSS framework |
| `_robots.html.twig` | `noindex, nofollow` meta when the tenant disables search engine indexing |
| `_history_bar.html.twig` | Heartbeat history bar (dashboard rows, monitor detail) |
| `_legacy_layout.html.twig` | Deprecated BC bridge around a 1.x layout that renders `uptime_content` (removed in 2.0) |
| `dashboard/_sidebar_tree.html.twig`, `_sidebar_row.html.twig`, `_sidebar_group_header.html.twig` | Monitor tree (groups / projects) |
| `dashboard/_quick_stats.html.twig` | Quick stats (`kpis` / `kpi*` slots, `data-stat-*`) |
| `dashboard/_events_body.html.twig` | Recent checks `<tbody>` |
| `dashboard/_layout_fragment.html.twig` | XHR fragment (tree + stats + events) for live layout refresh; not a page |
| `dashboard/_table_view.html.twig` | Table dashboard (`web_ui.dashboard.view: table`, 1.12): counts, UiKit responsive table with row actions in a `menu`, latest checks with the `pagination` partial, `empty` with New monitor. Variables: `monitor_rows`, `monitor_row_actions`, `monitor_targets`, `dashboard_empty`, `dashboard_header`, `events_page`, `events_pagination` |
| `monitor/_monitor_edit_modal.html.twig` | Monitor dialog: the UiKit `dialog` partial (native `<dialog>`, header / body; `data-uptime-monitor-form-modal`) |
| `monitor/_monitor_form_modal.html.twig`, `_monitor_form_body.html.twig` | Monitor form fragment loaded into the dialog (`X-Uptime-Modal`); not a page |
| `monitor/_monitor_form_fields.html.twig` | Monitor fieldsets per type + REQ-TWIG-003 child loop (form page and dialog) |

The bundle ships no local pagination, flash, confirm, modal shell, page header or empty-state partial: the
scaffolds and the UiKit partials (`action`, `dialog`, `confirm`, `detail_list`, `empty`, `flashes`, `pagination`,
`row_actions`, `card`, `responsive_table`) render them.

Since 1.12 the dashboard and the monitor page also render `page_sections` (`before` / `after`: the templates and
variables a host added through `Event\UptimePageEvent`); the monitor page renders one check log page (`log_events`,
`log_pagination`, `web_ui.per_page`).

## Navigation

`Nowo\UptimeMonitorBundle\Ui\UptimeAreaNav` (an `AreaNavProviderInterface`) publishes the bundle navigation to the
UiKit area-nav registry: `nowo_ui_area_nav('uptime_monitor', {tenantSlug: 'main'})` (Dashboard | Settings | Status
page) and `nowo_ui_area_nav('uptime_monitor_settings', {tenantSlug: 'main'})` (the settings screens). The settings
page itself renders the same screens as the `SettingsPage` section tabs.

## Allowed exceptions

- (none) — the contract test declares no `allowedClasses()`.
