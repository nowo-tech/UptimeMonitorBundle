# Usage Guide

## Table of contents

- [Dashboard](#dashboard)
- [Settings](#settings)
- [Public status page](#public-status-page)
- [REST API](#rest-api)
- [Monitors and check types](#monitors-and-check-types)
- [Commands](#commands)
- [Notifications](#notifications)
- [Host integration (tenants, access, events)](#host-integration-tenants-access-events)
- [Overriding templates](#overriding-templates)
  - [Forms in templates (REQ-TWIG-005)](#forms-in-templates-req-twig-005)
  - [Layout and CSS stack (REQ-UI-001 / REQ-UI-003)](#layout-and-css-stack-req-ui-001--req-ui-003)

## Dashboard

Operator UI (CRUD, charts, polling):

```
/uptime/{tenantSlug}
```

Default tenant slug: `main` (see `multi_tenant.default_tenant`).

The dashboard polls `GET /api/uptime/{tenantSlug}/summary` every `dashboard.poll_interval_ms` milliseconds. Pass `?since=<ISO8601>` to receive only monitors whose last check changed after that timestamp.

Use **Settings** in the toolbar or open `/uptime/{tenantSlug}/settings` for tenant-wide options (appearance, tags, backup, history retention). See [SETTINGS.md](SETTINGS.md).

## Settings

Uptime Kuma–style **Settings** are per tenant:

```
/uptime/{tenantSlug}/settings
```

Covers General, Appearance, Notifications (YAML summary), Reverse proxy, Tags, Monitor history, Backup, and About. Full field reference: [SETTINGS.md](SETTINGS.md).

Monitor-level options (HTTP timeout, retries, status ranges, headers, etc.): [MONITOR-CONFIGURATION.md](MONITOR-CONFIGURATION.md).

## Public status page

Read-only page for end users (no edit/delete actions):

```
/status/{tenantSlug}
```

Configure path and visibility:

```yaml
nowo_uptime_monitor:
    status_page:
        enabled: true
        path: /status
        title: null          # defaults to tenant display name
        show_latency: true
```

Paused monitors are hidden on the status page.

## REST API

| Endpoint | Description |
|----------|-------------|
| `GET /api/uptime/{tenant}/summary` | Monitor list + last check (polling) |
| `GET /api/uptime/{tenant}/monitors/{id}/aggregates?period=day&days=30` | Chart series for one monitor |
| `GET /api/uptime/{tenant}/aggregates/overview?days=7` | Tenant overview chart data |

## Monitors and check types

See [CHECK-TYPES.md](CHECK-TYPES.md). Supported types: HTTP, HTTPS, TCP, DNS, SSL certificate, Ping (ICMP).

Create monitors via Twig UI (`/uptime/{tenant}/monitors/new`) or programmatically with the `Monitor` entity and `MonitorFactory`.

HTTP/HTTPS monitors support Uptime Kuma–compatible options (retries, `200-299`, request timeout, proxy, Basic auth, upside-down mode). See [MONITOR-CONFIGURATION.md](MONITOR-CONFIGURATION.md).

## Commands

| Command | Purpose |
|---------|---------|
| `nowo:uptime:sync-schema` | Create/update Doctrine tables (`--force` on empty DB) |
| `nowo:uptime:seed-demo` | Seed default tenant + sample monitors |
| `nowo:uptime:run-due-checks` | Run checks that are due now |
| `nowo:uptime:rollup` | Build hourly/daily aggregates from detail rows |
| `nowo:uptime:purge-detail` | Purge old `CheckResult` rows per retention (keyset batches of `retention.purge_batch_size`) |
| `nowo:uptime:clear-data` | Delete all checks, aggregates, and incidents (keeps tenants/monitors; `--tenant`, `-n`) |

Production: enable Symfony Scheduler and consume `scheduler_default` (see [SCHEDULING.md](SCHEDULING.md)).

## Notifications

On status change (up/down/degraded), optional email, webhook, and Slack channels. See [NOTIFICATIONS.md](NOTIFICATIONS.md).

## Host integration (tenants, access, events)

For hosts that own their tenants (e.g. one tenant per project of the host application), since 1.9.0:

- **Tenants**: `TenantProvisioner::ensure($slug, $name)` creates a tenant once (an existing one is kept);
  `setEnabled($slug, false)` hides it. Set `tenants.list_enabled: false` so users do not create tenants themselves.
- **Per-tenant access**: when the service set as `security.access_checker` implements
  `TenantAwareUptimeMonitorAccessCheckerInterface`, every request whose route has `{tenantSlug}` is decided by
  `canAccessTenant` / `canManageTenantMonitors` / `canManageTenantSettings` for that tenant; the global methods still
  decide the routes without a tenant.
- **URLs** (1.9.1): import the routes with `type: nowo_uptime_monitor` and put `{tenantSlug}` in `dashboard.path`
  (`/projects/{tenantSlug}/uptime`) to serve the tenant pages under the host's own URLs.
- **Look**: wrap the pages in `.uptime-app.uptime-app--embedded` (bridge layout); the theme follows `data-theme` or
  `data-bs-theme` on an ancestor and the host maps the `--tblr-*` tokens to its palette.
- **Events**: `MonitorWentDownEvent` (when an incident opens) and `MonitorRecoveredEvent` (when it resolves) carry the
  monitor and the incident; they are dispatched before the check is flushed, so a listener may persist its own
  entities in the same unit of work.

```php
#[AsEventListener]
public function onDown(MonitorWentDownEvent $event): void
{
    $this->issues->openFor($event->monitor, $event->message);
}
```

## Overriding templates

The bundle registers the Twig namespace **`@NowoUptimeMonitorBundle/`**. **`TwigPathsPass`** maps **`templates/bundles/NowoUptimeMonitorBundle/`** with **`prependPath()`** when that folder exists, then registers the bundle **`src/Resources/views`** path with **`addPath()`**, so application copies are tried before vendor templates. You do not need entries in **`config/packages/twig.yaml`** for this.

**Freeze rule:** an application file at the override path **always wins** and will **not** pick up vendor changes for that path until you remove or merge it. Prefer config / surgical overrides when you want upgrades to keep applying.

**Procedure (app):**

1. Take the template path relative to the bundle views root (the **`<subpath>`**).
2. Create **`templates/bundles/NowoUptimeMonitorBundle/<subpath>`** in your project (same relative path).
3. Clear the Twig / Symfony cache in dev if needed: **`php bin/console cache:clear`**.

**Example override tree:**

```text
templates/bundles/NowoUptimeMonitorBundle/
├── dashboard/index.html.twig
├── monitor/show.html.twig
└── status/index.html.twig
```

Prefer **not** copying pages: since 1.11 they are UiKit page scaffolds (REQ-UI-003). Theme every page once through
`nowo_ui_kit` (layouts, `css_framework`, `theme`), and for a bundle-specific change point
`nowo_uptime_monitor.web_ui.templates.<page>` at a thin template that extends the bundle page and overrides one data
block — see [TEMPLATES.md](TEMPLATES.md) for the pages, view models and data blocks:

```twig
{# templates/uptime/dashboard.html.twig — web_ui.templates.dashboard #}
{% extends '@NowoUptimeMonitorBundle/dashboard/index.html.twig' %}
{% block nowo_ui_dashboard_cards %}
    <p class="{{ nowo_ui_class('muted', page.bundle) }}">Maintenance window every Sunday 02:00 UTC.</p>
    {{ parent() }}
{% endblock %}
```

### Forms in templates (REQ-TWIG-005)

Every form in the bundle views is a PHP Symfony Form Type (Form Kit `uptime_monitor` profile) created by the controller and rendered with `form_start` / the child loop / `form_end`. CSRF-only actions (purge history, clear statistics, delete tag) use `CsrfActionFormType`; the backup import uses `BackupImportFormType` (`FileType`, multipart); the dashboard sidebar filter uses `MonitorSearchFormType` (client-side only, never submitted). Field names and CSRF token ids are unchanged (`_token`, `action`, `import_mode`, `backup`).

```twig
{# settings/history.html.twig: the section form through the scaffold, CSRF actions through the UiKit action partial #}
{% block nowo_ui_settings_section %}
    {{ parent() }}
    {% for action in history_actions %}
        {{ include(nowo_ui_partial('action', page.bundle), { action: action, bundle: page.bundle }, false) }}
    {% endfor %}
{% endblock %}
```

Template overrides and the demo **must not** reintroduce raw `<form>`, `<input>`, `<textarea>` or `<select>` markup; `make check-no-raw-html-form` (part of `release-check`) fails when they do. The form variables each view receives are listed in [UPGRADING](UPGRADING.md#unreleased).

### Layout and CSS stack (REQ-UI-001 / REQ-UI-003)

| Concern | Key (1.11+) | Deprecated 1.x key |
|---------|-------------|--------------------|
| Admin layout | `nowo_ui_kit.layouts.admin`, or `nowo_uptime_monitor.web_ui.layout_template` (null = UiKit) | `templates.layout` / `templates.layout_template` |
| CSS stack, row actions, theme | `nowo_ui_kit.css_framework`, `nowo_ui_kit.theme` | `ui.framework` / `ui.css_framework` (seed only) |
| Form theme | `web_ui.form_themes` (default UiKit `nowo_ui_layout`) | per-framework `_form_theme.html.twig` (removed) |
| Per-page template | `web_ui.templates.<page>` | copying pages into `templates/bundles/` |

Pages extend the UiKit scaffolds and fill the canonical blocks: the layout renders `nowo_ui_document_title`,
`nowo_ui_head`, `nowo_ui_styles`, `nowo_ui_navigation`, `nowo_ui_page_header`, `nowo_ui_flashes`, `nowo_ui_content`,
`nowo_ui_modals` and `nowo_ui_scripts`; bundle CSS / JS stack with **`{{ parent() }}`** in `nowo_ui_styles` /
`nowo_ui_scripts`, so host assets stay. The bundle ships no layout and loads no CDN. The public status page is a
unique page on `nowo_ui_kit.layouts.guest`.

The Twig globals `uptime_layout` / `nowo_uptime_layout_template` (deprecated `templates.layout`) and
`uptime_ui_framework` are kept for 1.x host templates but no bundle page reads them. The tenant theme (`light` /
`dark` / `auto`) is the function **`uptime_theme()`** (evaluated per request).

Row actions, the monitor dialog (native `<dialog>`, UiKit `dialog` partial) and the confirm dialogs (Clear all
statistics, delete tag) are UiKit partials driven by `View\Action` / `View\Confirm`; include the UiKit scripts in
your layout (`nowo_ui_partial('scripts')`, already in the UiKit layouts). The bundle navigation is published to the
UiKit area-nav registry (`nowo_ui_area_nav('uptime_monitor', {tenantSlug: …})`, `uptime_monitor_settings`).

See also [CONFIGURATION.md](CONFIGURATION.md) for `web_ui.*` details and [TEMPLATES.md](TEMPLATES.md) for every page.
