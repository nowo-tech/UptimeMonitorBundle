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
        max_timeout_seconds: 60    # cap of every per-monitor timeout (HTTP/TCP/SSL; ping ≤ 30 s)
        max_body_bytes: 1048576    # HTTP body read only for a keyword, at most this many bytes
        max_redirects: 20          # cap of the per-monitor redirect limit
        max_scan_seconds: 300      # time budget of one due-check scan; 0 = none (left-over monitors run next tick)
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
        provisioner: null      # since 1.21: service id of a Tenant\MissingTenantProvisionerInterface (creates a missing tenant)
    tenants:
        list_enabled: true
        redirect_when_single: false
    web_ui:                    # UiKit page contract (REQ-UI-003)
        enabled: null          # null = dashboard.enabled (true)
        path_prefix: null      # URL prefix of the admin routes, may hold {tenantSlug}; null = dashboard.path (/uptime)
        # More URL prefixes for the tenant pages, by tenant slug prefix (path_prefix must hold {tenantSlug}).
        # Each copies the tenant routes as <name_prefix><route>; links to a matching slug use the copy.
        tenant_paths: []
        #   - { slug_prefix: 'user-', path: '/uptime/{tenantSlug}', name_prefix: 'personal_', slug_requirement: null }
        strict_tenant_paths: false   # since 1.21: true = a tenant answers only under the path its slug selects (else 404)
        layout_template: null  # null = nowo_ui_kit.layouts.admin; else a layout rendering the UiKit canonical blocks
        templates: {}          # per-page override, e.g. { dashboard: 'uptime/dashboard.html.twig' } (ids: docs/TEMPLATES.md)
        form_themes: ['@NowoUiKitBundle/form/nowo_ui_layout.html.twig']   # [] = only twig.form_themes
        dashboard:             # since 1.12
            view: tree         # tree (live monitor tree) | table (monitors table + paginated latest checks)
            header: tenant     # tenant (name, slug as intro) | monitors ("Monitors": the host shows the tenant)
            actions: [settings, status_page, new_monitor]   # header actions, in this order, each one by access
            new_monitor: header   # since 1.22: header | hidden (never in the header; the host places its own opener)
        settings:              # since 1.13
            sections: [general, appearance, notifications, reverse_proxy, tags, history, backup, about]   # the others: 404
            sections_resolver: null   # since 1.21: service id of a Ui\SettingsSectionsResolverInterface (narrows per tenant)
        monitor_show:          # since 1.21
            charts: bundle     # bundle | host (slots.charts instead of the bundle charts) | none
            slots: { figures: null, charts: null, details: null }   # templates rendered in place of a region
        per_page: 20           # check log (monitor page) and latest checks (table dashboard) rows per page
        back_links: true       # since 1.14.2: false drops the "← Dashboard" link (the host's own tabs lead back)
    limits:                    # since 1.12: New monitor disabled with the reason, the form refuses beyond them
        provider: null         # service id of a Limits\TenantLimitsInterface (per-tenant limits); null = the values below
        max_monitors: 0        # per tenant, groups not counted; 0 = no limit
        min_interval_seconds: 30
    dashboard:
        # enabled: true        # deprecated (1.11): web_ui.enabled
        # path: /uptime        # deprecated (1.11): web_ui.path_prefix
        sync: polling          # polling | mercure (requires symfony/mercure-bundle)
        poll_interval_ms: 30000
        # tenant_paths: []     # deprecated (1.11): web_ui.tenant_paths
        mercure:
            topic_template: '/uptime/{tenant}'
            private: true
    form:
        hidden_fields: []      # MonitorFormType fields left out (e.g. project, resendNotificationAfterDown)
    status_page:
        enabled: true
        default_public: true   # tenants without a choice in Settings → General; false = status pages are opt-in
        path: /status
        title: null
        show_latency: true
    push:                      # since 1.14: push monitors (server health), see PUSH-MONITORS.md
        enabled: true          # false = the ingest route answers 404
        path: /uptime-push     # POST <path>/{key}: one public route for every tenant; keep it outside the dashboard path
        max_body_bytes: 8192   # 512 – 65536 (413 above)
        signature_window_seconds: 300   # 30 – 3600, accepted clock skew of a signed push
        min_push_seconds: 10   # floor of the per-monitor rate limit (429 + Retry-After)
    notifications:
        enabled: false
        cooldown_seconds: 300
        http_timeout: 10      # webhook/Slack delivery deadline (s): HttpClient timeout + max_duration
    security:
        access_roles: [ROLE_ADMIN]   # general gate (REQ-UI-002)
        # access_checker: App\Security\MyUptimeMonitorAccessChecker
        allow_unauthenticated: false # DEV/DEMO only when true
        denied_tenant_status: 403    # 404 hides tenants the checker refuses (1.23, UM-7)
        roles:                       # null = the deprecated *_roles key (default [ROLE_ADMIN])
            dashboard: [ROLE_ADMIN]   # was security.dashboard_roles (deprecated 1.10)
            manage: [ROLE_ADMIN]      # was security.manage_roles
            settings: [ROLE_ADMIN]    # was security.settings_roles
```

Deprecated since **1.11** (REQ-UI-003 vocabulary, removed in 2.0): `templates.layout` / `templates.layout_template`,
`ui.*`, `dashboard.enabled`, `dashboard.path`, `security.dashboard_roles` / `manage_roles` / `settings_roles` — see
[UPGRADING](UPGRADING.md#configuration-req-ui-003-vocabulary).

### Check limits (SEC-004)

Monitor managers choose each monitor's timeout, redirects and target; the `checks.max_*` caps keep one monitor from
stalling (tarpit) or exhausting memory in the scan every tenant shares:

| Key | Default | Effect |
|-----|---------|--------|
| `checks.max_timeout_seconds` | `60` | Every HTTP / TCP / SSL timeout is clamped to `[1, max]`; the monitor form refuses larger values. Ping stays capped at 30 s. |
| `checks.max_body_bytes` | `1048576` | HTTP checks never buffer a body they do not need: it is read only for a keyword check and only up to this size (the transfer is cancelled after). |
| `checks.max_redirects` | `20` | Upper bound of the per-monitor redirect limit. |
| `checks.max_scan_seconds` | `300` | Time budget of one scan; when spent, the remaining monitors stay due and run first on the next tick. `0` disables it. |

A check whose runner throws is stored as `unknown` (`Check failed (<exception class>)`) and rescheduled; a monitor whose
execution fails (e.g. the database write) is logged and skipped, the scan goes on. Check messages (≤ 2000 characters)
and metadata (strings ≤ 1000 characters, ≤ 50 entries per list, ≤ 4 levels) are scrubbed to valid UTF-8 before they are
stored. If a flush still closes the entity manager, the scan resets it through the Doctrine registry, reschedules the
failing monitor (`next_check_at` = now + interval) and continues with the remaining monitors.

### Embedding the pages in a host (1.12)

A host that shows the bundle pages inside its own navigation (tabs, its own settings and status pages):

- `web_ui.dashboard.view: table`, `header: monitors` and `actions: [new_monitor]` — a monitors table under the host's
  title, without the bundle's Settings / Status page buttons;
- a tenant-aware access checker (`TenantAwareUptimeMonitorAccessCheckerInterface`): users without
  `canManageTenantMonitors()` see the pages without New monitor, Edit, Pause or Delete;
- `limits.provider`: a `Nowo\UptimeMonitorBundle\Limits\TenantLimitsInterface` service with the host's plans or
  instance settings;
- listeners of `Event\UptimePageEvent` (header actions, sections before / after the dashboard or a monitor page) and
  `Event\StatusPageMonitorsEvent` (the monitors of a public status page) instead of template overrides.

### New monitor outside the header (1.22)

`web_ui.dashboard.new_monitor: hidden` keeps New monitor out of the dashboard header, also when it is disabled at the
monitor limit (default `header`: today's button). The empty-state button stays. The host places its own opener:

- any element with `data-uptime-open="new-monitor"` (a page actions menu item, a button) opens the monitor form
  dialog on the dashboard (`uptime-dashboard.js`): the dialog carries the New monitor URL and title
  (`data-uptime-new-monitor-url` / `-title`) only while the user may add a monitor; otherwise the opener does nothing
  (a link is followed). A disabled opener (`disabled` / `aria-disabled="true"`) is ignored;
- the dashboard template variable `uptime_new_monitor` (`Nowo\UptimeMonitorBundle\Ui\NewMonitorState`, also visible
  to the host layout's blocks; `null` when the user may not manage the tenant's monitors): `url`, `label`,
  `disabled()` and `reason` — UiKit labels, so `{{ uptime_new_monitor.reason|nowo_ui_trans }}` prints "You can add up
  to N monitors" for a disabled item or notice.

```twig
{% set _new = uptime_new_monitor ?? null %}
{% if _new %}
    <button type="button" data-uptime-open="new-monitor"{% if _new.disabled() %} aria-disabled="true" title="{{ _new.reason|nowo_ui_trans }}"{% endif %}>{{ _new.label|nowo_ui_trans }}</button>
{% endif %}
```

### Host hooks (1.21)

Everything below is off by default; each replaces a host listener or template override.

| Need | Option / hook | Contract |
| --- | --- | --- |
| Monitor page regions | `web_ui.monitor_show.charts: bundle\|host\|none`, `web_ui.monitor_show.slots.{figures,charts,details}` | A slot is a template rendered with the page variables (`monitor`, `latest`, `detail`, `chart_series_json`, `api_history_url`, …) in place of the bundle region. `charts: host` draws no bundle chart and renders `slots.charts`; `none` renders no chart. The regions are also the blocks `monitor_details`, `monitor_figures`, `monitor_charts`. Slot templates are configuration (trusted); they must escape what they print. `monitor` is the raw entity, with the unmasked `target` and `config`, the push key and the parent group, and on the push-token response `push_secret` is in the context too: print `monitor_safe.target` and `monitor_safe.config` (masked with `SecretRedactor`; `monitor_safe.config` still holds `auth_username`, so leave that key out for such users), and never print the rest to a user who may only read shared monitors. |
| Host quota on create / resume | listener of `Event\UptimeEvents::MONITOR_QUOTA_CHECK` (`Event\MonitorQuotaCheckEvent`) | See [Host quota check](#host-quota-check). `limits.provider` still applies. |
| Create tenants on demand | `multi_tenant.provisioner` → `Tenant\MissingTenantProvisionerInterface::provision(string $tenantSlug): ?Tenant` | Called on kernel.controller (priority -8, after the access check) for the admin pages and the tenant APIs when the slug has no tenant; return the flushed tenant of that slug, or null → 404. A slug outside `TenantFormType::SLUG_PATTERN` answers 404 first. Only safe requests provision: a POST never does (its CSRF token is checked later, by the controller) and meets the missing tenant's 404, so the first visit must be a GET. It runs for **every user the checker lets read**, read-only users included: with a checker that ignores the slug, decide in the provisioner who may create what and return null otherwise. Never for the status page or the push endpoint. With `security.allow_unauthenticated: true` there is no access check before it: the provisioner decides alone. |
| Monitor-level read access | access checker implementing `Security\MonitorAwareUptimeMonitorAccessCheckerInterface::canViewMonitor(object $user, Monitor $monitor): bool` | Decides the monitor page and its history / aggregates APIs (404 when false, unknown or of another tenant). Tenant-wide reads (dashboard rows included, summary, status API) and every mutation keep `canAccessTenant()` / `canManageTenantMonitors()`: the dashboard is **not** filtered per monitor, so return `canAccessTenant()` false for users who may only read shared monitors, or they see the whole tenant. Those users then get 403 on the tenant-wide routes (404 with `security.denied_tenant_status: 404`, 1.23), and on the monitor page only the group children `canViewMonitor()` allows, with no parent group name and no push ingest URL. A group's own roll-up still counts all its children (`1/2 child monitor(s) up`). |
| Settings per tenant | `web_ui.settings.sections_resolver` → `Ui\SettingsSectionsResolverInterface::sections(string $tenantSlug): list<string>` | Narrows `web_ui.settings.sections` for that tenant (404 for the rest; with none, no Settings action or tab). |
| One path per tenant kind | `web_ui.strict_tenant_paths: true` | A slug that starts with a `tenant_paths` `slug_prefix` answers only under that copy, any other slug only under `path_prefix`; anything else is 404 (e.g. `/projects/user-…/uptime`). **Admin routes only:** the public status page (`/status/{slug}`) and the tenant APIs (`/api/uptime/{slug}/…`) have one path and are not covered; they keep their own checks (`status_page`, the access checker). |

#### Host quota check

`MONITOR_QUOTA_CHECK` fires inside the tenant row lock, before anything is written, on every write of the admin UI or a backup import that may count against a host quota:

| Write | `action` | `type` | `monitor` | `previousType` |
| --- | --- | --- | --- | --- |
| New monitor (form) or new backup row | `create` | its type | null | null |
| Edit or backup overwrite that changes the type (group into check, `https` into `push`, any other change) | `create` | the new type | the monitor, not changed yet | the old type |
| Edit or backup overwrite that unchecks `paused` | `resume` | the type it will have | the monitor, not changed yet | null |
| Pause action on a paused monitor | `resume` | its type | the monitor | null |

One edit that does both dispatches `create`, then `resume`; the first denial stops. Groups fire too: the listener decides what it counts, and a listener that counts two types alike should ignore a `create` whose `type` and `previousType` count the same. To charge a per-type limit, read `type`. Pausing, and an edit that changes neither, fire nothing. `nowo:uptime:apply-monitors` does not fire it (an operator command with its own `limits` check).

`deny($reason)` refuses the write: the monitor form shows the reason (dialog: 422), the pause action flashes it as `error`, an import skips the row and counts it as `quota_denied` (first reason in `quota_denied_reason`, shown in a `warning` flash) and goes on with the other rows. Each written row is flushed before the next check, so a listener that counts monitors sees it.

**Call `deny()`; do not throw.** A listener that throws also refuses the write, but the exception crosses the transaction and closes the EntityManager for the rest of the request (and for the next one in worker mode, unless the host resets it). An import turns it into a generic failure with nothing written.

The lock is per tenant: a host limit spanning several tenants is not serialised by it.

### Public status page

`/status/{tenant}` is public for a tenant when its *Settings → General → Public status page* is **public**, or **default**
and `status_page.default_public` is `true` (the 1.x behaviour, default). **private** — or a disabled or unknown tenant —
answers the same 404. Set `default_public: false` to make status pages opt-in per tenant.

### Push monitors

`POST <push.path>/{key}` (route `nowo_uptime_push`, default `/uptime-push/{key}`) is the ingest of push monitors
(since 1.14). It is **public**: no session or CSRF, the per-monitor bearer token (optionally plus an HMAC signature) is
the credential, and every refusal before authentication is the same 401. It exists only where the host imports the
bundle `routes.yaml`; `push.enabled: false` makes it answer 404. A host with a firewall on every URL lets it through
anonymously and rate-limits it at the reverse proxy:

```yaml
# config/packages/security.yaml
security:
    access_control:
        - { path: ^/uptime-push/, roles: PUBLIC_ACCESS }
```

Token, signature, payload, thresholds and the agent: [PUSH-MONITORS.md](PUSH-MONITORS.md). Threat model:
[SECURITY.md](SECURITY.md#threat-model-and-mitigations).

### Form Kit profile

The admin forms (monitor, tenant, tag, settings, backup import, search, CSRF-only actions) are FormKit Types:
`AbstractUptimeFormType` carries `#[FormKitConfig('uptime_monitor')]` + `FormOptionsTrait` (REQ-TWIG-005 rule 11).
When NowoFormKitBundle is registered, the extension prepends `nowo_form_kit.profiles.uptime_monitor` **unless the host
defines that profile**, and `nowo_form_kit.css_framework: bootstrap` unless the host sets `css_framework`:

```yaml
# seeded by the bundle (do not copy it unless you change it)
nowo_form_kit:
    profiles:
        uptime_monitor:
            alias: uptime_monitor
            translation_domain: NowoUptimeMonitorBundle
            convention_requires_translation: true   # convention label / placeholder / help keys only when translated
            defaults:
                attr: []       # no framework classes: the UiKit form theme (web_ui.form_themes) styles the fields
                row_attr: []
```

To theme the forms, define the profile in the host (`config/packages/nowo_form_kit.yaml`); the host profile replaces
the seeded one as a whole (the bundle then prepends nothing for it), so keep `translation_domain: NowoUptimeMonitorBundle`:

```yaml
nowo_form_kit:
    profiles:
        uptime_monitor:
            alias: uptime_monitor
            translation_domain: NowoUptimeMonitorBundle
            defaults:
                attr: { class: 'form-control' }
                row_attr: { class: 'mb-3' }
```

Prefer `nowo_ui_kit.theme.classes` (`input`, `form_row`, …) for classes shared by every Nowo bundle. Without
NowoFormKitBundle in the kernel nothing is prepended. Before 1.11 the seeded profile added Bootstrap classes: see
[UPGRADING](UPGRADING.md#formkit-profile).

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
| HTTP/HTTPS check (Symfony HttpClient) | `timeout` (idle) = monitor timeout; `max_duration` (total) = monitor timeout + 2 s | Monitor form **Timeout** (`config.request_timeout_seconds` / `config.timeout`, min 1 s; 48 s preset in the form, 10 s when unset), clamped to `checks.max_timeout_seconds` |
| HTTPS certificate-expiry probe (`check_cert_expiry`) | none of its own: reads the certificate chain of the HTTP check connection | — |
| TCP check (`stream_socket_client`) | connect timeout | monitor `config.timeout` (default 10 s), clamped to `checks.max_timeout_seconds` |
| SSL check (`ssl://` connect + handshake) | connect/handshake timeout | monitor `config.timeout` (default 10 s), clamped to `checks.max_timeout_seconds` |
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
| `security.access_roles` | `[ROLE_ADMIN]` | General gate: user needs **at least one** role. Empty = the area list alone decides. |
| `security.access_checker` | `null` | Optional service id implementing `UptimeMonitorAccessCheckerInterface`. |
| `security.allow_unauthenticated` | `false` | DEV/DEMO only: skip bundle access checks (and allow missing SecurityBundle). **Never** in production. |
| `security.denied_tenant_status` | `403` | `403` or `404` (1.23). With `404` and a tenant-aware checker, a refusal on a `{tenantSlug}` route answers 404 when `canAccessTenant()` is false, with the answer of a missing tenant: `{"error":"Tenant not found"}` (`application/json`) on the APIs, `Tenant "<slug>" not found.` on the pages. Members refused one action keep 403; requests without a tenant keep 403. **Checker contract:** `canAccessTenant()` must return false for unknown slugs too, or a missing tenant passes the check and answers differently from a refused one (existence leaks). Anonymous users get 404 instead of the login redirect unless `access_control` requires login on these paths first. |
| `security.dashboard_roles` | `[ROLE_ADMIN]` | Dashboard / read / polling API. Empty = no area check beyond `access_roles`. |
| `security.manage_roles` | `[ROLE_ADMIN]` | Monitor CRUD mutations (`new` / `edit` / `delete` / pause). |
| `security.settings_roles` | `[ROLE_ADMIN]` | Tenant settings UI, tenant creation (`/tenants/new`) and any unmapped unsafe-method (POST/PUT/PATCH/DELETE) admin action (fail closed). |

**Empty lists never mean "everyone"** (1.12.x): an area is allowed when the user holds a role of each non-empty list
among `access_roles` and that area's list (`security.roles.<action>` / `*_roles`); when both are empty the area is
denied to everyone. With the web UI on and the built-in checker, `access_roles` and every area list empty fails the
container compile.

Enforcement is via `UptimeMonitorAccessSubscriber` on `kernel.controller` (not Twig alone). The public status page (`status_page.path`, default `/status`) is **not** gated.

Host firewall example for the admin prefix (`dashboard.path`, default `/uptime`):

```yaml
# config/packages/security.yaml
security:
    access_control:
        - { path: ^/uptime, roles: ROLE_ADMIN }
```

When `dashboard.enabled` is `true` and `allow_unauthenticated` is `false`, the bundle requires `symfony/security-bundle` to be registered (compile-time `LogicException` otherwise).

### Host layout and theme (UiKit page contract, REQ-UI-003)

The admin pages are UiKit page scaffolds ([TEMPLATES.md](TEMPLATES.md)). They render inside
`nowo_ui_kit.layouts.admin` (public status page: `nowo_ui_kit.layouts.guest`); set your project layout (or a one-file
bridge that renders the UiKit canonical blocks) there once for every Nowo bundle, or only for this bundle:

```yaml
nowo_ui_kit:
    css_framework: bootstrap5      # bootstrap5 | bootstrap4 | tailwind | foundation | tabler | custom | none
    layouts:
        admin: 'layouts/admin.html.twig'
    theme:
        tokens: { primary: '#0b5fff' }

nowo_uptime_monitor:
    web_ui:
        layout_template: null      # or 'layouts/uptime.html.twig' for this bundle only
        templates:
            dashboard: 'uptime/dashboard.html.twig'   # extends @NowoUptimeMonitorBundle/dashboard/index.html.twig
```

The bundle loads no CSS framework and no CDN: its stylesheet (`uptime-dashboard.css`, `uptime-theme.css`, asset
package `nowo_uptime_monitor`) is stacked by the pages in `nowo_ui_styles` after `{{ parent() }}`, its scripts in
`nowo_ui_scripts`. Bootstrap, Tailwind, Foundation or `custom` markup comes from `nowo_ui_kit.css_framework` and
`nowo_ui_kit.theme.classes` (theme slots); per-bundle exceptions go in
`nowo_ui_kit.theme.bundles.NowoUptimeMonitorBundle`.

A 1.x layout that renders `{% block uptime_content %}` (deprecated `templates.layout`, or an override of the former
`@NowoUptimeMonitorBundle/layout.html.twig`) is wrapped by the deprecated bridge
`@NowoUptimeMonitorBundle/_legacy_layout.html.twig`.

### Embedded host theme

The bundle widgets (monitor tree, history bars, charts, status badges) are wrapped in `.uptime-app.uptime-app--embedded`
and take their colours from the UiKit `--nowo-ui-*` tokens (then the Tabler `--tblr-*` tokens, then the bundle
defaults). They follow `data-theme` / `data-bs-theme` on an ancestor; a tenant that forces `light` or `dark` in
Settings → Appearance sets `data-theme` on the widgets.

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

### UI framework (deprecated `ui.*`)

Since **1.11** the markup follows `nowo_ui_kit.css_framework` (no per-framework templates, no CDN). `ui.framework`
(alias `ui.css_framework`: `tabler`, `custom`, `bootstrap`, `tailwind`) only seeds `nowo_ui_kit.css_framework` when
the host does not set it (`bootstrap` → `bootstrap5`); `ui.tabler.*`, `ui.bootstrap.*` and `ui.tailwind.*` have no
effect. The tenant setting **Settings → Appearance → UI framework** is kept in the tenant JSON but no longer changes
the markup.

### Retention: infinite detail history

Set `retention.detail_days` to `0` to disable automatic purge (Uptime Kuma: “0 = infinite retention”). Per-tenant overrides are available in **Settings → Monitor history**; see [SETTINGS.md](SETTINGS.md).

### Retention: purge batch size

The detail purge (`nowo:uptime:purge-detail`, **Settings → Monitor history → Purge**) and `nowo:uptime:clear-data` delete in keyset batches instead of one unbounded `DELETE`: each batch selects up to `retention.purge_batch_size` ids (`id > last id`, ordered by id, platform `LIMIT`) and deletes them by id list in a short autocommit statement, until a short batch. This avoids long table locks and huge binlog/undo entries on large `uptime_check_result` tables. Default `1000`; lower it for busier tables, raise it to reduce round-trips.

See also [SCHEDULING.md](SCHEDULING.md), [MERCURE.md](MERCURE.md), [SETTINGS.md](SETTINGS.md), and [MONITOR-CONFIGURATION.md](MONITOR-CONFIGURATION.md).
