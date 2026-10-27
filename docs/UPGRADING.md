# Upgrading Guide

This guide helps you upgrade between versions of the Uptime Monitor Bundle.

## Table of contents

- [Current compatibility baseline](#current-compatibility-baseline)
- [From 1.3.7 to 1.3.8](#from-137-to-138)
- [From 1.3.6 to 1.3.7](#from-136-to-137)
- [Unreleased](#unreleased)
- [To 1.12.0](#to-1120)
  - [Template contract](#template-contract)
- [To 1.11.0](#to-1110)
  - [Security hardening (SEC-004 2026-10-27)](#security-hardening-sec-004-2026-10-27)
  - [Clock and check seams (REQ-DI-001)](#clock-and-check-seams-req-di-001)
  - [Template contract (REQ-UI-003)](#template-contract-req-ui-003)
  - [Configuration (REQ-UI-003 vocabulary)](#configuration-req-ui-003-vocabulary)
  - [FormKit profile](#formkit-profile)
  - [Demo (contributors)](#demo-contributors)
- [To 1.10.0](#to-1100)
- [To 1.9.4](#to-194)
- [To 1.9.3](#to-193)
- [To 1.9.2](#to-192)
- [To 1.9.1](#to-191)
- [To 1.9.0](#to-190)
- [To 1.8.0](#to-180)
  - [Forms rendered through Form Types (REQ-TWIG-005)](#forms-rendered-through-form-types-req-twig-005)
- [To 1.7.4](#to-174)
- [To 1.7.3](#to-173)
- [To 1.7.2](#to-172)
- [To 1.7.1](#to-171)
- [To 1.7.0](#to-170)
- [To 1.6.1](#to-161)
- [To 1.6.0](#to-160)
- [To 1.5.0](#to-150)
- [To 1.4.0](#to-140)
- [To 1.3.11](#to-1311)
- [To 1.3.10](#to-1310)
- [To 1.3.9](#to-139)
- [To 1.3.6](#to-136)
- [To 1.3.5](#to-135)
- [To 1.3.4](#to-134)
- [To 1.3.3](#to-133)
- [To 1.3.2](#to-132)
- [To 1.3.1](#to-131)
- [To 1.3.0](#to-130)
  - [Twig Extra Bundle (REQ-TWIG-004)](#twig-extra-bundle-req-twig-004)
  - [Twig-CS-Fixer (maintainers)](#twig-cs-fixer-maintainers)
- [Upgrading to 1.2.0 (2026-08-03)](#upgrading-to-120-2026-08-03)
  - [Install / update](#install--update)
  - [Behaviour change (admin access)](#behaviour-change-admin-access)
  - [Breaking changes](#breaking-changes)
- [Upgrading to 1.1.1 (2026-07-30)](#upgrading-to-111-2026-07-30)
  - [Behaviour change (backup import CSRF)](#behaviour-change-backup-import-csrf)
- [Upgrading to 1.1.0 (2026-07-30)](#upgrading-to-110-2026-07-30)
  - [REQ-UI-001 aliases and host layout stacking (optional)](#req-ui-001-aliases-and-host-layout-stacking-optional)
- [Upgrading to 1.0.11 (2026-07-29)](#upgrading-to-1011-2026-07-29)
  - [Security defaults (UI-002)](#security-defaults-ui-002)
  - [Ping SSRF](#ping-ssrf)
- [Upgrading to 1.0.10 (2026-07-22)](#upgrading-to-1010-2026-07-22)
- [Upgrading to 1.0.9 (2026-07-22)](#upgrading-to-109-2026-07-22)
- [Upgrading to 1.0.8 (2026-07-16)](#upgrading-to-108-2026-07-16)
- [Upgrading to 1.0.7 (2026-07-13)](#upgrading-to-107-2026-07-13)
- [Upgrading to 1.0.6 (2026-07-13)](#upgrading-to-106-2026-07-13)
- [Upgrading to 1.0.5 (2026-07-08)](#upgrading-to-105-2026-07-08)
- [Upgrading to 1.0.4 (2026-07-08)](#upgrading-to-104-2026-07-08)
- [Upgrading to 1.0.3 (2026-07-06)](#upgrading-to-103-2026-07-06)
- [Upgrading to 1.0.2 (2026-07-06)](#upgrading-to-102-2026-07-06)
- [Upgrading to 1.0.1 (2026-07-06)](#upgrading-to-101-2026-07-06)
- [Upgrading to 1.0.0 (2026-07-06)](#upgrading-to-100-2026-07-06)
  - [What's included](#whats-included)
  - [Fresh install](#fresh-install)
  - [Optional configuration](#optional-configuration)
  - [Notes](#notes)
  - [Breaking changes](#breaking-changes-1)
  - [FormKitBundle (admin forms)](#formkitbundle-admin-forms)

## Current compatibility baseline

- PHP: `>=8.2 <8.6`
- Symfony components: `^7.4 || ^8.0`

## From 1.3.7 to 1.3.8

No breaking changes. **No application upgrade steps.** Schema sync is more tolerant of already-existing MySQL foreign keys.

```bash
composer update nowo-tech/uptime-monitor-bundle
```

## From 1.3.6 to 1.3.7

No breaking changes. **No application upgrade steps.**

```bash
composer update nowo-tech/uptime-monitor-bundle
```

## Unreleased

## To 1.12.0

```bash
composer update nowo-tech/uptime-monitor-bundle
```

Nothing is required; every new option keeps the 1.11 behaviour by default.

- **Actions by access**: the dashboard's Settings and New monitor and a monitor's Edit now appear only for users the
  access checker allows (`canManageTenantSettings` / `canManageTenantMonitors`, or `canManageSettings` /
  `canManageMonitors`). A checker that refused those actions already answered 403; now the buttons are gone too.
- **New options**: `web_ui.dashboard.view` (`tree` | `table`), `web_ui.dashboard.header` (`tenant` | `monitors`),
  `web_ui.dashboard.actions` (`settings`, `status_page`, `new_monitor`), `web_ui.per_page` (20), `limits.provider`,
  `limits.max_monitors` (0 = no limit), `limits.min_interval_seconds` (30, the former HTML minimum, now validated).
- **Host limits**: implement `Nowo\UptimeMonitorBundle\Limits\TenantLimitsInterface` (`maxMonitors()`,
  `minIntervalSeconds()` per tenant slug) and set its service id as `limits.provider`; a host form extension that
  enforced its own limits can go.
- **Host pages**: listen to `Nowo\UptimeMonitorBundle\Event\UptimePageEvent` to add header actions and sections
  (`addSection('template', [vars], 'before'|'after')`) instead of overriding `dashboard` / `monitor_show`, and to
  `StatusPageMonitorsEvent` to choose the monitors of a public status page instead of overriding `status`.
- **Constructor additions** (optional, appended): `MonitorController` (`UptimeActions`, `TenantQuota`, event
  dispatcher, `web_ui`, `form.hidden_fields`), `DashboardController` (`UptimeActions`, event dispatcher, `web_ui`),
  `StatusPageController` (event dispatcher); `MonitorController::show()` takes the request (optional). New repository
  method `MonitorRepository::countChecksByTenantSlug()`.

### Template contract

- `dashboard/index.html.twig`: new variables `dashboard_view`, `dashboard_header`, `page_sections`; with the table
  view the data block renders `dashboard/_table_view.html.twig` (variables in [TEMPLATES.md](TEMPLATES.md)).
- `monitor/show.html.twig`: new variables `page_sections`, `log_events`, `log_pagination`; the check log section
  carries `data-testid="uptime-monitor-log"`, the history / figures / charts / log sit in `[data-detail-pending]`
  (hidden until the first check) after a `[data-detail-unchecked]` notice.
- `monitor/_monitor_form_fields.html.twig`: renders `form_errors(form)` first and each field only when the form has it.

## To 1.11.0

From **1.10.0**:

```bash
composer update nowo-tech/uptime-monitor-bundle
```

### Security hardening (SEC-004 2026-10-27)

- **CSRF is mandatory for the admin UI**: the container no longer compiles when `web_ui.enabled` is `true` and
  `framework.form.csrf_protection` (or `framework.csrf_protection` / `symfony/security-csrf`) is off. Enable CSRF, or
  set `nowo_uptime_monitor.web_ui.enabled: false` — the admin pages (dashboard, monitors, settings, tenants) then are
  not registered and answer 404; the status page and the JSON APIs stay.
- **New caps** (`checks.max_timeout_seconds: 60`, `max_body_bytes: 1048576`, `max_redirects: 20`,
  `max_scan_seconds: 300`): a monitor configured with a longer timeout or more redirects now runs with the cap; a scan
  that takes longer than 5 minutes leaves the remaining monitors for the next tick (`max_scan_seconds: 0` restores the
  unbounded scan); raise the
  cap if you really need longer checks (keep it below your worker limits). Keyword checks only see the first
  `max_body_bytes` of the body.
- **Status pages**: unchanged by default (`status_page.default_public: true`). Set it to `false` to make status pages
  opt-in, then mark tenants public in *Settings → General → Public status page*. Disabled tenants now answer 404 and
  their monitors are no longer checked (as `TenantProvisioner::setEnabled()` always promised).
- **Stored check data**: check messages are capped at 2000 characters and metadata strings at 1000 (≤ 50 entries per
  list, ≤ 4 levels); invalid UTF-8 bytes become `?`.
- **Backups**: exported files no longer contain URL / proxy credentials, credential-like query parameter values, request
  bodies, basic-auth passwords or header values
  (re-enter them after an import); imports above 2 MB or 1000 monitors are refused, rows that fail the monitor
  constraints are skipped and counted as *invalid*. `MonitorBackupService::import()` returns an extra `invalid` key.
- **Removed settings**: *Steam API key* and *Chromium executable* (stored but never used). They disappear from the
  General settings form and are dropped from the tenant settings on the next save.
- **Tenant slugs**: `TenantProvisioner::ensure()` throws `InvalidArgumentException` for a slug that is not lowercase
  letters, digits and hyphens (same rule as the tenant form).
- **Constructor additions** (all optional, appended): `HttpCheckRunner` takes `CheckLimits` instead of the former
  `SocketConnectorInterface` (5th argument — the certificate probe now uses the HTTP connection), `TcpCheckRunner` /
  `SslCheckRunner` / `MonitorFormType` / `DueChecksRunner` / `TenantController` / `SettingsController` /
  `MonitorBackupService` / `UptimeDataClearService` / `DetailRetentionService` gain trailing optional arguments
  (limits, logger, validator, access checker); `DueChecksRunner` also takes an optional `ManagerRegistry`,
  `UptimeMonitorAccessSubscriber` a `$webUiEnabled` flag and `UptimeMonitorRouteLoader` the `web_ui` array.

### Clock and check seams (REQ-DI-001)

- `symfony/clock` is now required (already installed with `symfony/scheduler`). Freezing time in tests: replace the
  `clock` service (or pass a `Symfony\Component\Clock\MockClock` to the bundle services you construct yourself).
- Code that calls `DueChecksRunner::runDueChecks()` without argument keeps working; passing `null` now means "now".
- Custom subclasses are not affected (the bundle services are final); hosts constructing `TcpCheckRunner`,
  `SslCheckRunner`, `HttpCheckRunner`, `PingCheckRunner` or `MonitorUrlSsrfGuard` by hand can keep their arguments:
  the new ones are optional and appended at the end.

### Template contract (REQ-UI-003)

The admin pages are UiKit page scaffolds (UiKit ^2.15.1); see [TEMPLATES.md](TEMPLATES.md) for every page →
scaffold, view model and data block.

1. **Layout.** Pages render in `nowo_ui_kit.layouts.admin` (public status page: `.guest`). Set it once for every
   Nowo bundle, or set `nowo_uptime_monitor.web_ui.layout_template` for this bundle only. The layout renders the
   UiKit canonical blocks (`nowo_ui_document_title`, `nowo_ui_head`, `nowo_ui_styles`, `nowo_ui_navigation`,
   `nowo_ui_page_header`, `nowo_ui_flashes`, `nowo_ui_content`, `nowo_ui_modals`, `nowo_ui_scripts`):

   ```twig
   {# templates/layouts/admin.html.twig #}
   {% extends 'base.html.twig' %}
   {% block title %}{% block nowo_ui_document_title %}{% endblock %}{% endblock %}
   {% block stylesheets %}{{ parent() }}<link rel="stylesheet" href="{{ asset('css/nowo-ui.css', 'nowo_ui_kit') }}">{% block nowo_ui_head %}{% endblock %}{% block nowo_ui_styles %}{% endblock %}{% endblock %}
   {% block body %}
       {% block nowo_ui_navigation %}{% endblock %}
       {% block nowo_ui_page_header %}{% endblock %}
       {% block nowo_ui_flashes %}{{ include(nowo_ui_partial('flashes')) }}{% endblock %}
       {% block nowo_ui_content %}{% endblock %}
       {% block nowo_ui_modals %}{% endblock %}
   {% endblock %}
   {% block javascripts %}{{ parent() }}{% block nowo_ui_scripts %}{{ include(nowo_ui_partial('scripts')) }}{% endblock %}{% endblock %}
   ```

   A 1.x layout set in the deprecated `templates.layout` (or an application override of
   `@NowoUptimeMonitorBundle/layout.html.twig`) that renders `{% block uptime_content %}` keeps working through the
   deprecated bridge `@NowoUptimeMonitorBundle/_legacy_layout.html.twig` (deprecation notice; removed in 2.0).
2. **CSS framework and theme.** The bundle no longer loads Tabler / Bootstrap / Tailwind from CDNs and no longer
   switches markup per `ui.framework`: set `nowo_ui_kit.css_framework` and `nowo_ui_kit.theme` (classes, tokens) once.
   `ui.framework` still seeds `nowo_ui_kit.css_framework` when the host leaves it unset (deprecated). The tenant
   "UI framework" setting no longer changes the markup; the tenant theme (light / dark / auto) still applies.
3. **Removed templates / blocks.** `layout.html.twig`, `_form_theme.html.twig`, `_monitor_actions.html.twig`,
   `settings/_layout.html.twig`, `settings/_nav.html.twig`; page blocks `uptime_content`, `settings_body`, `title`,
   `stylesheets`, `javascripts`. Overrides in `templates/bundles/NowoUptimeMonitorBundle/` of a page must be redone on
   the new data blocks — prefer `web_ui.templates.<page>` with a thin
   `{% extends '@NowoUptimeMonitorBundle/<page>' %}` overriding `nowo_ui_dashboard_cards`, `nowo_ui_show_after`,
   `nowo_ui_form_body`, `nowo_ui_settings_section`, `nowo_ui_list_row`, … Page CSS / JS stack in `nowo_ui_styles` /
   `nowo_ui_scripts` with `{{ parent() }}`.
4. **Monitor dialog.** `monitor/_monitor_edit_modal.html.twig` is a native `<dialog>` (UiKit `dialog` partial,
   `data-uptime-monitor-form-modal`, `data-monitor-form-body`); Bootstrap JS is no longer needed. Rebuilt
   `uptime-dashboard.js` / `uptime-monitor-detail.js` ship with the bundle (`assets:install`).
5. **Confirmations.** Clear all statistics and tag delete open the UiKit confirm dialog; load the UiKit scripts in
   your layout (`nowo_ui_partial('scripts')`, included by the UiKit layouts).

### Configuration (REQ-UI-003 vocabulary)

| Deprecated (1.x) | Use |
| --- | --- |
| `templates.layout` / `templates.layout_template` | `web_ui.layout_template` (null = `nowo_ui_kit.layouts.admin`) |
| `dashboard.enabled` | `web_ui.enabled` |
| `dashboard.path` | `web_ui.path_prefix` |
| `dashboard.tenant_paths` (1.10.0) | `web_ui.tenant_paths` (same entries; `TenantPathUrlGenerator` unchanged) |
| `security.dashboard_roles` / `manage_roles` / `settings_roles` | `security.roles.dashboard` / `manage` / `settings` |
| `ui.framework` / `ui.css_framework` | `nowo_ui_kit.css_framework` |
| `ui.tabler.skip_cdn`, `ui.bootstrap.*`, `ui.tailwind.*` | nothing (no CDN; the host layout loads its CSS) |

New: `web_ui.templates.<page>` (per-page template override, ids in TEMPLATES.md) and `web_ui.form_themes` (default
the UiKit form theme `@NowoUiKitBundle/form/nowo_ui_layout.html.twig`; `[]` = only your `twig.form_themes`).

### FormKit profile

When the host defines no `nowo_form_kit.profiles.uptime_monitor`, the seeded profile no longer adds Bootstrap
classes (`form-control`, `form-select`, `mb-2`, …) and sets `convention_requires_translation: true`. Hosts that
relied on those classes set them in their own profile or in `nowo_ui_kit.theme.classes` (`input`, `form_row`, …).

### Demo (contributors)

## To 1.10.0

- **Monitor name required**: saving a monitor with a blank name now fails validation (`form.monitor.name_required`).
  Existing monitors are untouched; give a name to any that has none before editing it.
- **New options, off by default**: `form.hidden_fields` (fields left out of the monitor form) and
  `dashboard.tenant_paths` (more URL prefixes per tenant slug prefix; needs `{tenantSlug}` in `dashboard.path`). The
  bundle now decorates the `router` service (`TenantPathUrlGenerator`); with no tenant path it only passes through.
- **Table prefix (REQ-ORM-002)**: new `nowo_uptime_monitor.doctrine.table_prefix` (default `''`). The default keeps
  the historical `uptime_*` table and index names, so **nothing changes unless you set it**. Setting it (e.g. `app_`)
  renames every bundle table, index and unique constraint (`app_uptime_monitor`, `app_idx_uptime_monitor_tenant_next`,
  …) in the Doctrine metadata only: rename the existing tables first (or generate a migration with
  `doctrine:migrations:diff` and review it), then run `nowo:uptime:sync-schema`. The deprecated root `table_prefix`
  key stays ignored; its unused `nowo_uptime_monitor.table_prefix` container parameter was removed (read
  `nowo_uptime_monitor.doctrine.table_prefix` instead).
- **Constraint messages (REQ-I18N-003)**: the `validators.<locale>.yaml` catalogues were removed. The tenant slug
  message `form.tenant.slug_regex` is now read from the `NowoUptimeMonitorBundle` domain (it was already there). If you
  overrode it in your app's `translations/validators.<locale>.yaml`, move the override to
  `translations/NowoUptimeMonitorBundle.<locale>.yaml`.
- **Webhook / Slack delivery**: new `notifications.http_timeout` (default `10` seconds, as before) now caps the whole
  delivery (`max_duration`) and not only the idle time. A non-2xx answer is now reported as a delivery failure (logged
  at `error`, the endpoint origin only) instead of being ignored.
- **Logging**: notification failures are logged with a redacted message or the exception class (never the raw
  exception message, which may contain the webhook URL); successes are logged at `info`. See
  [SECURITY.md → Logging policy](SECURITY.md#logging-policy).
- **Demo** (contributors only): the demo uses the Mercure hub built into FrankenPHP (no `mercure` container, no host
  port `3080`); `MERCURE_URL` is `http://php/.well-known/mercure`. Re-copy `demo/symfony8/.env.example` to `.env` (or
  drop `MERCURE_PORT` and update `MERCURE_URL`) and run `make -C demo up-symfony8`.

## To 1.9.4

From **1.9.3**: no change needed (CSS fix). A host that relied on the bundle stylesheet's side margins on its own form
fields keeps its own styles now.

## To 1.9.3

From **1.9.2**: no change needed (bug fix).

## To 1.9.2

From **1.9.1**: no change needed (bug fixes). A host template that read the monitor type select's option values gets
the type names (`https`) instead of indexes (`2`).

## To 1.9.1

From **1.9.0**: no change needed. Optional, to put the tenant inside your own URLs:

```yaml
# config/routes/nowo_uptime_monitor.yaml (instead of importing Resources/config/routes.yaml)
nowo_uptime_monitor:
    resource: .
    type: nowo_uptime_monitor
```

```yaml
# config/packages/nowo_uptime_monitor.yaml
nowo_uptime_monitor:
    dashboard:
        path: '/projects/{tenantSlug}/uptime'
    tenants:
        list_enabled: false
```

## To 1.9.0

From **1.8.0**:

```bash
composer update nowo-tech/uptime-monitor-bundle
```

No change needed. New, opt-in, for hosts that own their tenants:

- **Per-tenant access**: implement `Nowo\UptimeMonitorBundle\Security\TenantAwareUptimeMonitorAccessCheckerInterface`
  on the service you set as `security.access_checker` (it extends the global interface: keep the three global
  methods for the tenant index and tenant creation).
- **Events**: listen to `Nowo\UptimeMonitorBundle\Event\MonitorWentDownEvent` / `MonitorRecoveredEvent`
  (`#[AsEventListener]`). They are dispatched only when Symfony's event dispatcher is available (always in a full-stack
  app).
- **Tenants**: create them with `Nowo\UptimeMonitorBundle\Service\TenantProvisioner::ensure($slug, $name)` and turn
  the tenant UI off (`tenants.list_enabled: false`).

## To 1.8.0

From **1.7.4**:

```bash
composer update nowo-tech/uptime-monitor-bundle
```

### Forms rendered through Form Types (REQ-TWIG-005)

The controllers now pass Symfony forms for every action that used hand-rolled markup. They are always passed, so the shipped templates need no fallback; **if you override one of these templates**, replace the raw `<form>` / `<input>` markup with `form_start` / the child loop / `form_end` on these variables:

| Template | New variable | Form Type |
| -------- | ------------ | --------- |
| `settings/history.html.twig` | `purge_form`, `clear_form` | `CsrfActionFormType` (token ids `purge-history`, `clear-stats`) |
| `settings/tags.html.twig` | `tag_delete_forms` (map tag id → `FormView`) | `CsrfActionFormType` (token id `delete-tag-<id>`) |
| `settings/backup.html.twig` | `form` | `BackupImportFormType` (`action`, `import_mode`, `backup`, `_token`; token id `backup-import`) |
| `dashboard/index.html.twig` | `monitor_search_form` | `MonitorSearchFormType` (`q`, `data-monitor-search`) |

The posted payload is unchanged (flat `_token` and the same field names), so an old override keeps working until you migrate it, but `make check-no-raw-html-form` and REQ-TWIG-005 expect the Form helpers.

## To 1.7.4

From **1.7.3**:

```bash
composer update nowo-tech/uptime-monitor-bundle
```

## To 1.7.3

From **1.7.2**:

```bash
composer update nowo-tech/uptime-monitor-bundle
```

## To 1.7.2

From **1.7.1**:

```bash
composer update nowo-tech/uptime-monitor-bundle
```

## To 1.7.1

From **1.7.0**:

```bash
composer update nowo-tech/uptime-monitor-bundle
```

## To 1.7.0

From **1.6.1** — retention purge and clear-data delete in keyset batches.

```bash
composer update nowo-tech/uptime-monitor-bundle
```

- No action needed. The detail purge and `nowo:uptime:clear-data` now delete in batches of `retention.purge_batch_size` rows (default `1000`) instead of one statement; the deleted rows and reported counts are the same.
- Optional: tune the batch size (smaller = shorter locks, more round-trips):

  ```yaml
  nowo_uptime_monitor:
      retention:
          purge_batch_size: 1000
  ```

- Purging is no longer one atomic statement: if it is interrupted, the batches already run stay deleted (run it again to finish).

## To 1.6.1

From **1.6.0** — DNS checks honour block_private_urls (no internal records exposed)

```bash
composer update nowo-tech/uptime-monitor-bundle
```

- With `checks.block_private_urls: true` (default), DNS monitors whose hostname is a private IP literal or `localhost`, or whose answer contains a private/local address, now report **Down** (`… (blocked)`) and no longer store the resolved records. Set `block_private_urls: false` to monitor internal DNS names.

## To 1.6.0

From **1.5.0** — unresolvable ping hosts reported Down; host translator settings no longer forced.

```bash
composer update nowo-tech/uptime-monitor-bundle
```

- The bundle no longer forces `framework.translator.fallbacks: [en]` nor enables the translator. If you relied on that implicit `en` fallback (or on the translator being switched on by the bundle), set `framework.translator` (e.g. `fallbacks: ['en']`) in your application. Bundle translations still load automatically.
- Ping monitors whose host does not resolve now record **Down** (`Could not resolve host …`) instead of running `ping` against the name. No action needed.

## To 1.5.0

From **1.4.0** — host locales no longer forced, enabled switch honoured, never-read keys deprecated, vitest 4.

```bash
composer update nowo-tech/uptime-monitor-bundle
```

- **Locales:** the bundle no longer prepends `framework.default_locale: en` and `framework.enabled_locales: [en, es, de, fr, it, nl, pt]`. Those values merged into the host config (Symfony merges `enabled_locales` lists and the bundle could win on `default_locale`). Set both in your own `config/packages/translation.yaml` / `framework.yaml`; include the locales you want the UI in (bundle translations: en, es, de, fr, it, nl, pt).
- **`enabled: false`** now pauses monitoring (no scheduled checks; `nowo:uptime:run-due-checks` and `RunDueChecksMessage` run nothing). It was ignored before — if you had `enabled: false` set and still expect checks, remove it.
- **Deprecated keys** (never read; setting them now triggers a deprecation): `environments`, `table_prefix`, `aggregates.keep_forever`, `multi_tenant.enabled`, `multi_tenant.header`, `dashboard.prefix`, `dashboard.roles`. Remove them; replacements are listed in [CONFIGURATION.md](CONFIGURATION.md#monitoring-switch-and-deprecated-keys) (`dashboard.roles` → `security.dashboard_roles`).
- Dev dependencies: `symfony/browser-kit`, `dom-crawler` and `mailer` now `^7.4 || ^8.0`; CI no longer runs a Symfony 7.0 cell (composer already required 7.4+).

## To 1.4.0

From **1.3.11** — security: tenant creation gated, SSRF guard hardened, ping host validation.

```bash
composer update nowo-tech/uptime-monitor-bundle
```

- Users with only dashboard roles can no longer create tenants or send unmapped write requests; they need `settings_roles`.
- With `block_private_urls: true` (default), TCP/SSL monitors and HTTP redirects or proxies pointing at private/reserved addresses now report Down. Set `block_private_urls: false` for internal monitoring.
- Ping hosts starting with `-` are rejected.

## To 1.3.11

From **1.3.10** — dependency refresh and repository maintenance.

```bash
composer update nowo-tech/uptime-monitor-bundle
```

- No breaking changes. No application upgrade steps.

## To 1.3.10

From **1.3.9** — asset fix and dependency refresh.

```bash
composer update nowo-tech/uptime-monitor-bundle
php bin/console assets:install public
```

- No breaking changes. Re-run `assets:install` (or your asset-copy step) so the previously missing `assets/monitor-form-modal-CDzwPBdm.js` chunk is published; older hashed chunks in the installed bundle `assets/` directory can be deleted.

## To 1.3.9

From **1.3.8** — REQ-CS-008 Igor FrankenPHP worker audit (igor-php require-dev, igor.json, make igor).

```bash
composer update nowo-tech/uptime-monitor-bundle
php bin/console cache:clear
```

No configuration changes. If your own templates read the Twig globals `uptime_theme` or `uptime_ui_framework` to get the **tenant** theme / UI framework override, switch to the functions `uptime_theme()` and `uptime_ui_framework()`. The globals are now request-independent (configured `ui.framework` and `auto`) so they stay correct in FrankenPHP worker mode without a kernel reset. The bundle layout (`layout.html.twig`, `_stylesheets.html.twig`) still sets a local `uptime_ui_framework` variable from the function, so blocks rendered inside it keep working.
- No application upgrade steps for require-dev Igor tooling (REQ-CS-008). Consumers do not pull `igor-php/igor-php` transitively.

## To 1.3.6

From **1.3.5** — No application upgrade steps (SSRF regression tests only).

```bash
composer update nowo-tech/uptime-monitor-bundle
```

## To 1.3.5

Review Flex recipe `security_nowo_uptime_monitor.yaml` after update.

```bash
composer update nowo-tech/uptime-monitor-bundle
```

## To 1.3.4

From **1.3.3** — Review Flex recipe `security_nowo_uptime_monitor.yaml` access rules after install/update.

```bash
composer update nowo-tech/uptime-monitor-bundle
```

## To 1.3.3

From **1.3.2** — No application upgrade steps.

```bash
composer update nowo-tech/uptime-monitor-bundle
```

## To 1.3.2

From **1.3.1** — Review production config for SSRF hardening. Flex recipe `when@prod` sets:

```yaml
nowo_uptime_monitor:
    checks:
        block_private_urls: true
```

Replace placeholder `url_allowlist` entries before enabling checks against internal targets.

```bash
composer update nowo-tech/uptime-monitor-bundle
php bin/console cache:clear
```

## To 1.3.1

From **1.3.0** — No application upgrade steps. **Demos only:** Hot Reload Bundle `^1.4` (FrankenPHP Mercure/`hot_reload`, `dev`/`test`). Shipped demos are Symfony 8 only (Symfony 6/7 demo apps removed).

```bash
composer update nowo-tech/uptime-monitor-bundle
```

## To 1.3.0

From **1.2.0** — Adds FormKit and/or UiKit where applicable, Twig Extra (REQ-TWIG-004), and Twig-CS-Fixer. Register TwigExtraBundle, NowoFormKitBundle, and NowoUiKitBundle if Flex did not. See CHANGELOG.

```bash
composer update nowo-tech/uptime-monitor-bundle
php bin/console cache:clear
```

### Twig Extra Bundle (REQ-TWIG-004)

Hosts that render this bundle's Twig templates must install:

```bash
composer require twig/extra-bundle twig/string-extra
```

and enable `Twig\Extra\TwigExtraBundle\TwigExtraBundle`. Flex recipes usually register it automatically.

### Twig-CS-Fixer (maintainers)

Package maintainers: `composer twig:lint` / `composer twig:fix` use `.twig-cs-fixer.php` over `src/` (and `templates/` when present).

## Upgrading to 1.2.0 (2026-08-03)

Minor release: REQ-UI-002 completion — `security.access_roles` / `access_checker` / `allow_unauthenticated`, SecurityBundle compile-time guard, and `UptimeMonitorAccessSubscriber` enforcement on admin controllers.

### Install / update

```bash
composer require nowo-tech/uptime-monitor-bundle:^1.2
php bin/console cache:clear
```

### Behaviour change (admin access)

| Topic | Before | 1.2.0 |
| --- | --- | --- |
| General gate | Area `*_roles` only | `access_roles` (default `[ROLE_ADMIN]`) **plus** area roles |
| Enforcement | Twig / host firewall | Bundle `UptimeMonitorAccessSubscriber` on admin controllers |
| Apps without SecurityBundle | Could boot | Boot fails with `LogicException` unless `allow_unauthenticated: true` |

**Demos / trusted local kernels:**

```yaml
nowo_uptime_monitor:
    security:
        access_roles: []
        allow_unauthenticated: true   # never in production
        dashboard_roles: []
        manage_roles: []
        settings_roles: []
```

**Production:** keep `allow_unauthenticated: false`, install SecurityBundle, grant `access_roles` (and area roles), and protect `/uptime` with host `access_control`.

### Breaking changes

Apps that used the dashboard without SecurityBundle (or without matching `ROLE_ADMIN`) must install/configure SecurityBundle roles or set `allow_unauthenticated: true` for non-production use. The public status page (`/status`) is unchanged.

## Upgrading to 1.1.1 (2026-07-30)

### Behaviour change (backup import CSRF)

Settings backup **import** POST now requires a CSRF token (REQ-SEC-005), same pattern as history purge / clear-stats.

| Before | After |
|--------|--------|
| Import accepted without `_token` | Import ignored unless `_token` is valid for id `backup-import` |

If you POST to `nowo_uptime_settings_backup` with a custom form (not the bundle Twig), add:

```html
<input type="hidden" name="_token" value="{{ csrf_token('backup-import') }}">
```

The bundled `settings/backup.html.twig` already includes this field.

```bash
composer update nowo-tech/uptime-monitor-bundle
php bin/console cache:clear
```

## Upgrading to 1.1.0 (2026-07-30)

### REQ-UI-001 aliases and host layout stacking (optional)

**Non-breaking.** Existing `templates.layout` / `ui.framework` config keeps working. New optional aliases:

| Prefer (canonical) | Also accepted |
|--------------------|---------------|
| `templates.layout` | `templates.layout_template` |
| `ui.framework` | `ui.css_framework` |

Twig: `nowo_uptime_layout_template` mirrors `uptime_layout`. Prefer pointing `templates.layout` at your project layout instead of freezing every admin page (see [USAGE](USAGE.md#overriding-templates)).

```bash
composer require nowo-tech/uptime-monitor-bundle:^1.1.0
php bin/console cache:clear
```

## Upgrading to 1.0.11 (2026-07-29)

### Security defaults (UI-002)

Default `nowo_uptime_monitor.security.*_roles`, `security.access_roles`, and `dashboard.roles` are **`ROLE_ADMIN`**. `security.allow_unauthenticated` defaults to **`false`**. Admin UI access is enforced by `UptimeMonitorAccessSubscriber`.

To keep previous “allow all” demo behaviour:

```yaml
nowo_uptime_monitor:
    security:
        access_roles: []
        allow_unauthenticated: true   # demo/dev only
        dashboard_roles: []
        manage_roles: []
        settings_roles: []
```

Protect `/uptime` in the host app with Symfony `access_control` (see [CONFIGURATION.md](CONFIGURATION.md) / [SECURITY.md](SECURITY.md)).

### Ping SSRF

ICMP ping targets are blocked when they resolve to private/local networks if `checks.block_private_urls` is `true` (default), same guard as HTTP monitors.

```bash
composer update nowo-tech/uptime-monitor-bundle
php bin/console cache:clear
```

## Upgrading to 1.0.10 (2026-07-22)

Code style / demo lock follow-up to 1.0.9 (`import_symbols`). The bundle public API, configuration keys, and runtime behaviour are unchanged.

```bash
composer update nowo-tech/uptime-monitor-bundle
```

No application code or schema changes are required.

## Upgrading to 1.0.9 (2026-07-22)

Demo FrankenPHP mode switch (`FRANKENPHP_MODE`), demo lock sync, and frontend/dev tooling. The bundle public API, configuration keys, and runtime behaviour are unchanged for application consumers.

```bash
composer update nowo-tech/uptime-monitor-bundle
```

No application code or schema changes are required. If you run the demos, set `FRANKENPHP_MODE=classic|worker` in `.env` and recreate containers (`docker compose up -d`) after changing it.

## Upgrading to 1.0.8 (2026-07-16)

Maintainer/CI tooling, documentation, catalogue key sync, and PHPUnit coverage exclusions. The bundle public API, configuration keys, and runtime behaviour are unchanged for application consumers.

```bash
composer update nowo-tech/uptime-monitor-bundle
```

No application code or schema changes are required. Optional: if you fork or contribute, run `make setup-hooks` once per clone (REQ-GIT-001).

## Upgrading to 1.0.7 (2026-07-13)

Demo `config/reference.php` maintenance only (align with Symfony generator). The bundle API, configuration, and runtime behaviour are unchanged.

```bash
composer update nowo-tech/uptime-monitor-bundle
```

No application code or schema changes are required.

## Upgrading to 1.0.6 (2026-07-13)

Maintenance only (`.gitignore`, dev `composer.lock`, demo lock sync). The bundle API, configuration, and runtime behaviour are unchanged.

```bash
composer update nowo-tech/uptime-monitor-bundle
```

No application code or schema changes are required.

## Upgrading to 1.0.5 (2026-07-08)

Demo `composer.lock` and generated `config/reference.php` maintenance only. The bundle API, configuration, and runtime behaviour are unchanged.

```bash
composer update nowo-tech/uptime-monitor-bundle
```

No application code or schema changes are required.

## Upgrading to 1.0.4 (2026-07-08)

Documentation and maintainer tooling only (GitHub Spec Kit baseline). The bundle API, configuration keys, and runtime behaviour are unchanged.

```bash
composer update nowo-tech/uptime-monitor-bundle
```

No application code or schema changes are required.

## Upgrading to 1.0.3 (2026-07-06)

Demo `composer.lock` maintenance only. The bundle API, configuration, and runtime behaviour are unchanged.

```bash
composer update nowo-tech/uptime-monitor-bundle
```

No application code or schema changes are required.

## Upgrading to 1.0.2 (2026-07-06)

Makefile and dev-tooling only. The bundle API, configuration keys, and runtime behaviour are unchanged.

```bash
composer update nowo-tech/uptime-monitor-bundle
```

No application code or schema changes are required.

## Upgrading to 1.0.1 (2026-07-06)

Repository, CI, and test tooling only. The bundle API, configuration keys, and runtime behaviour are unchanged.

```bash
composer update nowo-tech/uptime-monitor-bundle
```

No application code or schema changes are required.

## Upgrading to 1.0.0 (2026-07-06)

Initial public release. There is no earlier tagged version to migrate from.

### What's included

- Public status page (`status_page` config, route `/status/{tenantSlug}` by default).
- Ping (ICMP) check runner and monitor form type.
- API summary supports `?since=` filtering for polling deltas.
- Demo seed: one project group plus local HTTP probe monitors (`demo_uptime_ok`, `demo_uptime_flaky`).
- Uptime Kuma–style **tenant settings** UI (`/uptime/{tenant}/settings`) and extended HTTP monitor options.
- `uptime_tenant.settings` JSON column and `uptime_tag` table.
- Mercure real-time dashboard sync (optional).

### Fresh install

```bash
composer require nowo-tech/uptime-monitor-bundle
php bin/console nowo:uptime:sync-schema
php bin/console assets:install public
```

Optional demo data:

```bash
php bin/console nowo:uptime:seed-demo
php bin/console nowo:uptime:run-due-checks
```

### Optional configuration

Status page:

```yaml
nowo_uptime_monitor:
    status_page:
        enabled: true
        path: /status
```

Mercure sync (requires `symfony/mercure-bundle`):

```yaml
nowo_uptime_monitor:
    dashboard:
        sync: mercure
```

### Notes

- **Ping monitors**: the PHP container/host must have the `ping` binary and (in Docker) often `CAP_NET_RAW`. See [CHECK-TYPES.md](CHECK-TYPES.md).
- **Settings & monitors**: see [SETTINGS.md](SETTINGS.md) and [MONITOR-CONFIGURATION.md](MONITOR-CONFIGURATION.md).
- **HTTP monitors**: private/local targets are blocked by default (SSRF guard). See [SECURITY.md](SECURITY.md).

### Breaking changes

None. This is the first stable release.
### FormKitBundle (admin forms)

If you use admin/dashboard Symfony forms, ensure `nowo-tech/form-kit-bundle` ^2.0 is installed (pulled transitively) and `Nowo\FormKitBundle\NowoFormKitBundle` is registered. Form types use profile `uptime_monitor` via `#[FormKitConfig]`; the bundle prepends that profile when the host has not defined it.
