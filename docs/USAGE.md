# Usage Guide

## Table of contents

- [Dashboard](#dashboard)
- [Settings](#settings)
- [Public status page](#public-status-page)
- [REST API](#rest-api)
- [Monitors and check types](#monitors-and-check-types)
- [Commands](#commands)
- [Notifications](#notifications)

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

## Template overrides

Override bundle Twig templates in your application:

```
templates/bundles/UptimeMonitorBundle/
├── dashboard/index.html.twig
├── monitor/show.html.twig
├── status/index.html.twig
└── layout.html.twig
```

Namespace in templates: `@NowoUptimeMonitorBundle/...`. Copy from `vendor/nowo-tech/uptime-monitor-bundle/src/Resources/views/` as a starting point.
