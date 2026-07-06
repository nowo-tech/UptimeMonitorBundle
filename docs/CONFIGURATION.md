# Configuration

```yaml
nowo_uptime_monitor:
    enabled: true
    connection: default
    table_prefix: uptime_
    checks:
        min_latency_ms: 0   # floor for stored latency (ms); use e.g. 1 for localhost
    scheduler:
        enabled: true
        mode: scheduler   # scheduler | cron | messenger
        tick: '1 minute'
    retention:
        detail_days: 30
        purge_enabled: true
    aggregates:
        keep_forever: true
        periods: [hour, day, month, year]
    multi_tenant:
        enabled: true
        header: X-Uptime-Tenant
        default_tenant: main
    tenants:
        list_enabled: true
        redirect_when_single: false
    ui:
        framework: tabler          # tabler | custom | bootstrap | tailwind
        tabler:
            skip_cdn: false        # true when host app serves Tabler (e.g. nowo-devkit Vite)
        bootstrap:
            css_url: 'https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css'
            js_url: 'https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js'
        tailwind:
            cdn_script: 'https://cdn.tailwindcss.com'   # Play CDN (dev/prototyping)
            css_url: null                               # optional compiled CSS URL (production)
    dashboard:
        enabled: true
        path: /uptime
        sync: polling          # polling | mercure (requires symfony/mercure-bundle)
        poll_interval_ms: 30000
        mercure:
            topic_template: '/uptime/{tenant}'
            private: true
    status_page:
        enabled: true
        path: /status
        title: null
        show_latency: true
    notifications:
        enabled: false
        cooldown_seconds: 300
    templates:
        layout: '@NowoUptimeMonitorBundle/layout.html.twig'
```

### Host app layout (nowo-devkit)

Override the bundle layout in your app to embed screens in your shell:

```twig
{# templates/bundles/NowoUptimeMonitorBundle/layout.html.twig #}
{% extends 'base.html.twig' %}
{# … include _stylesheets, wrap {% block uptime_content %}, skip bundle header … #}
```

```yaml
nowo_uptime_monitor:
    ui:
        framework: tabler
        tabler:
            skip_cdn: true
    templates:
        layout: '@NowoUptimeMonitorBundle/layout.html.twig'
```

Bundle admin templates extend the configurable `uptime_layout` global and fill `{% block uptime_content %}`. The public status page stays standalone.

### Embedded host theme

When the UI is rendered inside a host app shell (nowo-devkit), add class `uptime-app--embedded` on the root `.uptime-app` wrapper. The bundle maps `--uptime-*` tokens to `--tblr-*` / `--brand-*` via `[data-bs-theme]` on the host document.

### Tenant list UI

For single-tenant host apps (nowo-devkit), disable the `/tenants` index and redirect straight to the default tenant dashboard:

```yaml
nowo_uptime_monitor:
    multi_tenant:
        default_tenant: main
    tenants:
        list_enabled: false
```

Alternatively, keep the list enabled but redirect when only one tenant exists:

```yaml
    tenants:
        redirect_when_single: true
```

### Minimum latency

`checks.min_latency_ms` sets a **floor** for latency stored on each check (dashboard, status page, aggregates). Useful when timers report `0 ms` on fast or local targets. Default `0` disables the floor.

Per-monitor override (monitor `config` JSON / advanced setup):

```yaml
# example monitor config key
min_latency_ms: 5
```

### UI framework (Bootstrap / Tailwind)

`ui.framework` selects the styling stack for Twig screens:

| Value | Description |
|-------|-------------|
| `tabler` | Tabler CDN (unless `ui.tabler.skip_cdn`) + bundle CSS — default for nowo-devkit |
| `custom` | Bundle BEM CSS only (`uptime-dashboard.css` + `uptime-theme.css`) |
| `bootstrap` | Above + `uptime-ui-bootstrap.css` and Bootstrap 5 CDN + `bootstrap_5_layout` form theme |
| `tailwind` | Above + `uptime-ui-tailwind.css` and Tailwind CDN (or `ui.tailwind.css_url`) + `tailwind_2_layout` form theme |

Sources: `src/Resources/assets/scss/` (compiled with `pnpm run build` / `make assets`). Do not edit the generated CSS in `public/` by hand.

Per-tenant override: **Settings → Appearance → UI framework** (`settings.ui_framework` in tenant JSON). Use `default` to inherit the global YAML value.

### Retention: infinite detail history

Set `retention.detail_days` to `0` to disable automatic purge (Uptime Kuma: “0 = infinite retention”). Per-tenant overrides are available in **Settings → Monitor history**; see [SETTINGS.md](SETTINGS.md).

See also [SCHEDULING.md](SCHEDULING.md), [MERCURE.md](MERCURE.md), [SETTINGS.md](SETTINGS.md), and [MONITOR-CONFIGURATION.md](MONITOR-CONFIGURATION.md).
