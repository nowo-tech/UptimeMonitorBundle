# Usage Guide

## Table of contents

- [Dashboard](#dashboard)
- [Settings](#settings)
- [Public status page](#public-status-page)
- [REST API](#rest-api)
- [Monitors and check types](#monitors-and-check-types)
- [Commands](#commands)
- [Notifications](#notifications)
- [Overriding templates](#overriding-templates)

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
| `nowo:uptime:purge-detail` | Purge old `CheckResult` rows per retention |
| `nowo:uptime:clear-data` | Delete all checks, aggregates, and incidents (keeps tenants/monitors; `--tenant`, `-n`) |

Production: enable Symfony Scheduler and consume `scheduler_default` (see [SCHEDULING.md](SCHEDULING.md)).

## Notifications

On status change (up/down/degraded), optional email, webhook, and Slack channels. See [NOTIFICATIONS.md](NOTIFICATIONS.md).

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
├── status/index.html.twig
└── layout.html.twig
```

Copy from `vendor/nowo-tech/uptime-monitor-bundle/src/Resources/views/` as a starting point.

### Layout and CSS stack (REQ-UI-001 naming, BC preserved)

| Canonical (current) | Equivalent alias | Twig globals |
|---------------------|------------------|--------------|
| `templates.layout` | `templates.layout_template` | `uptime_layout` **and** `nowo_uptime_layout_template` (same value) |
| `ui.framework` | `ui.css_framework` | `uptime_ui_framework` |

Admin pages `{% extends uptime_layout %}` (or `nowo_uptime_layout_template`) and fill `{% block uptime_content %}`. Prefer pointing the layout at your project layout (or a one-file bridge) instead of copying every page:

```yaml
nowo_uptime_monitor:
    templates:
        # either key works:
        layout: 'base.html.twig'
        # layout_template: 'base.html.twig'
    ui:
        # either key works:
        framework: tabler
        # css_framework: tabler
```

Pages that add CSS/JS stack with **`{{ parent() }}`** in `stylesheets` / `javascripts` so host assets remain when you use a project layout. The default `@NowoUptimeMonitorBundle/layout.html.twig` is a **demo** full HTML document (Tabler / framework CDN via `_stylesheets.html.twig`); the public status page stays standalone.

See also [CONFIGURATION.md](CONFIGURATION.md) for `ui.*` / `templates.*` details.
