# Configuration

```yaml
nowo_uptime_monitor:
    enabled: true          # false = pause monitoring (no checks run); UI and data stay available
    connection: default
    # table_prefix: uptime_   # deprecated (1.5), no effect: use doctrine.table_prefix
    doctrine:
        table_prefix: ''      # prepended to every bundle table/index name; 'app_' => app_uptime_monitor
    checks:
        min_latency_ms: 0   # floor for stored latency (ms); use e.g. 1 for localhost
        block_private_urls: true   # SSRF guard for HTTP/TCP/SSL/ping/DNS monitors; false to monitor private networks
    scheduler:
        enabled: true
        mode: scheduler   # scheduler | cron | messenger
        tick: '1 minute'
    retention:
        detail_days: 30
        purge_enabled: true
        purge_batch_size: 1000   # rows per DELETE batch (purge-detail, clear-data); min 1
    aggregates:
        # keep_forever: true   # deprecated (1.5), no effect: aggregates are always kept
        periods: [hour, day, month, year]
    multi_tenant:
        # enabled: true                # deprecated (1.5), no effect
        # header: X-Uptime-Tenant      # deprecated (1.5), no effect: tenant comes from the URL
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
        # More URL prefixes for the tenant pages, by tenant slug prefix (dashboard.path must hold {tenantSlug}).
        # Each copies the tenant routes as <name_prefix><route>; links to a matching slug use the copy.
        tenant_paths: []
        #   - { slug_prefix: 'user-', path: '/uptime/{tenantSlug}', name_prefix: 'personal_', slug_requirement: null }
        mercure:
            topic_template: '/uptime/{tenant}'
            private: true
    form:
        hidden_fields: []      # MonitorFormType fields left out (e.g. project, resendNotificationAfterDown)
    status_page:
        enabled: true
        path: /status
        title: null
        show_latency: true
    notifications:
        enabled: false
        cooldown_seconds: 300
        http_timeout: 10      # webhook/Slack delivery deadline (s): HttpClient timeout + max_duration
    templates:
        layout: '@NowoUptimeMonitorBundle/layout.html.twig'
    security:
        access_roles: [ROLE_ADMIN]   # general gate (REQ-UI-002)
        # access_checker: App\Security\MyUptimeMonitorAccessChecker
        allow_unauthenticated: false # DEV/DEMO only when true
        dashboard_roles: [ROLE_ADMIN]
        manage_roles: [ROLE_ADMIN]
        settings_roles: [ROLE_ADMIN]
```

### Monitoring switch and deprecated keys

`enabled: false` pauses monitoring: the Scheduler provides no recurring message, and `nowo:uptime:run-due-checks` / `RunDueChecksMessage` execute nothing (cron and Messenger modes included). Dashboards, the status page and stored data keep working. Use it per environment, e.g. `when@test: { nowo_uptime_monitor: { enabled: false } }`.

These keys were never read by the bundle. Since **1.5** setting them triggers a deprecation; they will be removed in the next major:

| Key | Replacement |
|-----|-------------|
| `environments` | `enabled` per environment, or register the bundle only where needed in `config/bundles.php` |
| `table_prefix` | `doctrine.table_prefix` — prepended to the `uptime_*` names (see [Doctrine table prefix](#doctrine-table-prefix)) |
| `aggregates.keep_forever` | none — aggregates are always kept; remove them with `nowo:uptime:clear-data` |
| `multi_tenant.enabled` | none — tenants are resolved from the URL slug; for single-tenant apps use `tenants.list_enabled` / `tenants.redirect_when_single` |
| `multi_tenant.header` | none — tenants are resolved from the URL slug, not a header |
| `dashboard.prefix` | `dashboard.path` (URL prefix of every admin route) |
| `dashboard.roles` | `security.dashboard_roles` |

### Doctrine table prefix

`doctrine.table_prefix` (REQ-ORM-002) namespaces every bundle-owned table so several applications or bundles can share
one database. It is prepended by a Doctrine `loadClassMetadata` listener (`Nowo\UptimeMonitorBundle\Doctrine\TablePrefixListener`)
that only touches the bundle entities (`Nowo\UptimeMonitorBundle\Entity\*`). Index and unique-constraint names get
the same prefix (PostgreSQL index names are schema-wide). Allowed characters: letters, digits and `_`.

| `doctrine.table_prefix` | Tables | Example index |
|-------------------------|--------|---------------|
| `''` (default) | `uptime_tenant`, `uptime_monitor`, `uptime_check_result`, `uptime_check_aggregate`, `uptime_incident`, `uptime_tag` | `idx_uptime_monitor_tenant_next` |
| `'app_'` | `app_uptime_tenant`, `app_uptime_monitor`, `app_uptime_check_result`, `app_uptime_check_aggregate`, `app_uptime_incident`, `app_uptime_tag` | `app_idx_uptime_monitor_tenant_next` |

```yaml
nowo_uptime_monitor:
    doctrine:
        table_prefix: 'app_'
```

`nowo:uptime:sync-schema` and `doctrine:schema:update` / Doctrine Migrations read the same metadata, so they create the
prefixed names. Changing the prefix on an existing database does **not** rename tables: rename them yourself (or
migrate the data) before deploying the new prefix — see [UPGRADING.md](UPGRADING.md). The effective value is exposed as
the `nowo_uptime_monitor.doctrine.table_prefix` container parameter. The legacy root `table_prefix` key stays
deprecated and ignored.

### Timeouts (FrankenPHP-safe, REQ-RUNTIME-001)

Monitor checks are the only blocking outbound I/O of the bundle, and they never run inside a web request: they run in
the CLI / worker processes (`nowo:uptime:run-due-checks`, `messenger:consume scheduler_uptime_monitor`). Dashboards,
JSON APIs and the status page only read the database. Every outbound call has an explicit deadline:

| Blocking call | Deadline | Configured by |
|---------------|----------|---------------|
| HTTP/HTTPS check (Symfony HttpClient) | `timeout` (idle) = monitor timeout; `max_duration` (total) = monitor timeout + 2 s | Monitor form **Timeout** (`config.request_timeout_seconds` / `config.timeout`, min 1 s; 48 s preset in the form, 10 s when unset) |
| HTTPS certificate-expiry probe (`check_cert_expiry`) | 5 s connect + TLS handshake | constant |
| TCP check (`stream_socket_client`) | connect timeout | monitor `config.timeout` (default 10 s) |
| SSL check (`ssl://` connect + handshake) | connect/handshake timeout | monitor `config.timeout` (default 10 s) |
| Ping check (`ping` binary) | Linux `-W` reply wait **and** `-w` hard deadline; macOS `-t`; BSD `-W` (ms) | monitor `config.timeout` (default 5 s), clamped to 1–30 s |
| DNS check / SSRF guard lookups (`dns_get_record`) | system resolver deadline (`/etc/resolv.conf` `options timeout:N attempts:N`; glibc default 5 s × 2 per nameserver) | host OS — PHP offers no per-call timeout |
| Webhook / Slack notification (HttpClient) | `timeout` = `max_duration` = `notifications.http_timeout` | `notifications.http_timeout` (default 10 s, min 1 s) |
| E-mail notification (Symfony Mailer) | transport socket timeout (`default_socket_timeout`, PHP default 60 s) | host Mailer config — prefer an async (Messenger) mailer transport |
| Mercure publish (`dashboard.sync: mercure`) | host `http_client` defaults | `framework.http_client.default_options.timeout` / `max_duration` |

Hierarchy (innermost fires first; raise the outer layers in the same change when you raise an inner one):

| Layer | Default | Rule |
|-------|---------|------|
| Operation (table above) | 5–50 s per check, 10 s per webhook | Fires first; the check is stored as `down` with the transport error |
| Scan budget | sum of the due checks of one tick | Keep `scheduler.tick` and the number of slow monitors so a scan ends before the next tick |
| PHP `max_execution_time` | `0` in CLI | Workers are bounded by the operation deadlines; recycle them with `messenger:consume --time-limit=3600 --memory-limit=256M` |
| Web requests (FrankenPHP / FPM) | host | Bundle pages do no outbound I/O; if you trigger `run-due-checks` from HTTP, keep PHP `max_execution_time` strictly above the slowest scan and the proxy write timeout above PHP |
| FrankenPHP `max_wait_time` | demo: 30 s | Caps the wait for a free PHP thread (504 instead of an endless queue) |

Messenger transport, retry and failure-transport policy for `RunDueChecksMessage`:
[SCHEDULING.md → Messenger transport, retries and failures](SCHEDULING.md#messenger-transport-retries-and-failures).

The FrankenPHP demo values (PHP 60 s < Caddy `servers.timeouts.write` 75 s, `max_wait_time` 30 s) are documented in
[DEMO-FRANKENPHP.md](DEMO-FRANKENPHP.md#timeouts).

### Security (REQ-UI-002)

| Key | Default | Purpose |
|-----|---------|---------|
| `security.access_roles` | `[ROLE_ADMIN]` | General gate: user needs **at least one** role. Empty = no general role check. |
| `security.access_checker` | `null` | Optional service id implementing `UptimeMonitorAccessCheckerInterface`. |
| `security.allow_unauthenticated` | `false` | DEV/DEMO only: skip bundle access checks (and allow missing SecurityBundle). **Never** in production. |
| `security.dashboard_roles` | `[ROLE_ADMIN]` | Dashboard / read / polling API. Empty = no area check beyond `access_roles`. |
| `security.manage_roles` | `[ROLE_ADMIN]` | Monitor CRUD mutations (`new` / `edit` / `delete` / pause). |
| `security.settings_roles` | `[ROLE_ADMIN]` | Tenant settings UI, tenant creation (`/tenants/new`) and any unmapped unsafe-method (POST/PUT/PATCH/DELETE) admin action (fail closed). |

Enforcement is via `UptimeMonitorAccessSubscriber` on `kernel.controller` (not Twig alone). The public status page (`status_page.path`, default `/status`) is **not** gated.

Host firewall example for the admin prefix (`dashboard.path`, default `/uptime`):

```yaml
# config/packages/security.yaml
security:
    access_control:
        - { path: ^/uptime, roles: ROLE_ADMIN }
```

When `dashboard.enabled` is `true` and `allow_unauthenticated` is `false`, the bundle requires `symfony/security-bundle` to be registered (compile-time `LogicException` otherwise).

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

Bundle admin templates extend the configurable `uptime_layout` global (alias: `nowo_uptime_layout_template`) and fill `{% block uptime_content %}`. Config: `templates.layout` ≡ `templates.layout_template`. The public status page stays standalone.

### Embedded host theme

When the UI is rendered inside a host app shell (nowo-devkit), add class `uptime-app--embedded` on the root `.uptime-app` wrapper. The bundle maps `--uptime-*` tokens to `--tblr-*` / `--brand-*` via `[data-bs-theme]` or `[data-theme]` (since 1.9.1) on the host document; a host without Tabler defines the `--tblr-*` tokens it wants on `.uptime-app--embedded`.

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

`ui.framework` selects the styling stack for Twig screens (alias: `ui.css_framework` — same value):

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

### Retention: purge batch size

The detail purge (`nowo:uptime:purge-detail`, **Settings → Monitor history → Purge**) and `nowo:uptime:clear-data` delete in keyset batches instead of one unbounded `DELETE`: each batch selects up to `retention.purge_batch_size` ids (`id > last id`, ordered by id, platform `LIMIT`) and deletes them by id list in a short autocommit statement, until a short batch. This avoids long table locks and huge binlog/undo entries on large `uptime_check_result` tables. Default `1000`; lower it for busier tables, raise it to reduce round-trips.

See also [SCHEDULING.md](SCHEDULING.md), [MERCURE.md](MERCURE.md), [SETTINGS.md](SETTINGS.md), and [MONITOR-CONFIGURATION.md](MONITOR-CONFIGURATION.md).
