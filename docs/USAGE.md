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
- [Database and entity manager](#database-and-entity-manager)
  - [Standard host](#standard-host)
  - [DDD host](#ddd-host)
- [Using it from a DDD / hexagonal application](#using-it-from-a-ddd--hexagonal-application)
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

**Secrets in HTTP monitors.** The edit form, the monitor page and exports mask the URL / proxy userinfo and the values
of credential-like query parameters (`token`, `key`, `secret`, `pass`, `sig`, `auth`, `jwt`, `bearer`, `hash`…); the
form also masks every header value and (since 1.25.2) the request body. Leave `***` to keep a stored value, type to
replace it. Masking is by name: a secret in the URL path (a Slack webhook `/services/…`, a Telegram `/bot<token>/`) or
under another parameter name stays visible, so put secrets in headers, basic auth or a credential-named parameter. A
`***` with nothing stored behind it is refused by the form (since 1.25.2).

## Commands

| Command | Purpose |
|---------|---------|
| `nowo:uptime:sync-schema` | Create/update Doctrine tables (`--force` on empty DB) |
| `nowo:uptime:seed-demo` | Seed default tenant + sample monitors |
| `nowo:uptime:run-due-checks` | Run checks that are due now |
| `nowo:uptime:rollup` | Build hourly/daily aggregates from detail rows |
| `nowo:uptime:purge-detail` | Purge old `CheckResult` rows per retention (keyset batches of `retention.purge_batch_size`) |
| `nowo:uptime:clear-data` | Delete all checks, aggregates, and incidents (keeps tenants/monitors; `--tenant`, `-n`) |
| `nowo:uptime:apply-monitors` | (1.18) Create, update or prune push monitors from a monitors file: `--file=<json>`, `--token-file=<path>` or `--token-file=-` (stdin), `--tenant=<uuid>`, `--dry-run`, `--prune`. stdout is one JSON document; exit codes 0–6. See [PUSH-MONITORS.md → Monitors as code](PUSH-MONITORS.md#monitors-as-code) |

Production: enable Symfony Scheduler and consume `scheduler_default` (see [SCHEDULING.md](SCHEDULING.md)).

`apply-monitors` never takes a token on the command line. In a deploy step, pipe the token map on stdin from a
non-interactive process (Ansible `command` with `stdin:` and `no_log: true`, `docker compose exec -T`); a terminal
stdin is refused. Run it with `--dry-run` first and check exit 0. Monitors it creates are file-managed: the UI shows a
"Managed from file (key)" badge and refuses edit, delete and token changes (409), while pause / resume stays.

## Notifications

On status change (up/down/degraded), optional email, webhook, and Slack channels. See [NOTIFICATIONS.md](NOTIFICATIONS.md).

## Host integration (tenants, access, events)

For hosts that own their tenants (e.g. one tenant per project of the host application), since 1.9.0:

- **Tenants**: `TenantProvisionerInterface::ensure($slug, $name)` (implemented by `TenantProvisioner`, since 1.25)
  creates a tenant once (an existing one is kept); `setEnabled($slug, false)` hides it. Set `tenants.list_enabled: false` so users do not create tenants themselves.
- **Per-tenant access**: when the service set as `security.access_checker` implements
  `TenantAwareUptimeMonitorAccessCheckerInterface`, every request whose route has `{tenantSlug}` is decided by
  `canAccessTenant` / `canManageTenantMonitors` / `canManageTenantSettings` for that tenant; the global methods still
  decide the routes without a tenant.
- **URLs** (1.9.1): import the routes with `type: nowo_uptime_monitor` and put `{tenantSlug}` in `dashboard.path`
  (`/projects/{tenantSlug}/uptime`) to serve the tenant pages under the host's own URLs.
- **Look**: wrap the pages in `.uptime-app.uptime-app--embedded` (bridge layout); the theme follows `data-theme` or
  `data-bs-theme` on an ancestor and the host maps the `--tblr-*` tokens to its palette.
- **Events**: `MonitorWentDownEvent` (when an incident opens) and `MonitorRecoveredEvent` (when it resolves) carry the
  monitor and the incident, plus scalar getters (`getTenantSlug()`, `getMonitorId()`, `getIncidentId()`, `snapshot()`)
  since 1.25. Since 1.25 they are dispatched **after** the check is flushed: the new incident has its id, and a failed
  flush dispatches nothing. Entities a listener persists are flushed right after the dispatch (no longer in the same
  flush as the check).
- **Admin UI lifecycle events** (since 1.25): `MonitorCreatedEvent`, `MonitorUpdatedEvent` (with the changed field
  names), `MonitorDeletedEvent` and `MonitorPausedEvent` (`paused` true or false), dispatched after the commit with the
  tenant slug, monitor id, actor (`getUserIdentifier()` of the security token, often the login email, or null) and
  `occurredAt`. Pausing or resuming through the **edit form** sends `MonitorUpdatedEvent` with `paused` in
  `changedFields`, not `MonitorPausedEvent`: listen to both to audit pauses. A password change is reported as
  `authPassword` (the name only); since 1.25.1 a change of the URL, proxy or headers is reported as `url`, `proxy` or
  `httpHeaders`, also names only (the form shows those secrets masked), and since 1.25.2 a change of the request body
  as `httpBody`.

```php
#[AsEventListener]
public function onDown(MonitorWentDownEvent $event): void
{
    $this->issues->openFor($event->getTenantSlug(), (int) $event->getMonitorId(), $event->message);
}
```

## Database and entity manager

Since 1.27.0 the bundle reads and writes through **its own private entity manager** (`Doctrine\UptimeEntityManager`,
REQ-ARCH-001 rule 6). It is created on the connection of the bundle tables and shares the mapping configuration and
the event manager of the application manager that maps the bundle entities. Its unit of work holds only bundle rows:

- a bundle write (a port call such as `TenantProvisionerInterface::ensure()`, `MonitorApplierInterface::apply()`, a
  backup import, a check, an admin page) flushes **only** the bundle rows. A change your code left pending in your
  entity managers stays pending; the bundle never flushes, clears, closes or resets an application manager;
- the connection is shared: a transaction you open around a bundle call includes the bundle rows (your rollback
  removes them, your commit keeps them);
- a failed bundle flush closes only the bundle manager; the next call gets a fresh one, and committed rows are kept;
- worker mode (REQ-RUNTIME-002): the private manager is dropped at every boundary (main `kernel.request` and
  `kernel.terminate`, Messenger received / handled / failed, `console.command` / `console.terminate`) and by
  `kernel.reset`, so no request, message or command sees the entities of the previous one, with or without the
  services resetter.

The repository services (`Repository\MonitorRepository`, `TenantRepository`, ...) you inject keep working on **your**
manager, as before: entities you load through them can be related to your entities and flushed by you.
`TenantProvisionerInterface::ensure()` returns the tenant of that manager too. The services that take an entity
(`CheckExecutorService::execute()`, `MonitorBackupService::import()`) accept one of your manager and work on the bundle
copy of the same row.

**Copies.** `CheckExecutorService::execute()` / `MonitorBackupService::import()` given one of your entities work on the
bundle copy: the object you passed is not updated. SQL filters enabled on your manager are not copied to the bundle's
manager.

**Incident listeners.** Since 1.25 `MonitorWentDownEvent` / `MonitorRecoveredEvent` are dispatched after the check
commit and the bundle flushed what the listeners persisted. With `doctrine.flush_listener_changes: true` (the default in
1.x) the bundle still flushes the application manager that maps the bundle entities after those listeners, **only when
nothing was pending there before they ran** (so a change your code left pending is never flushed), and triggers a
deprecation when it does. Otherwise (option `false`, or your manager already dirty) the listener's changes are **not
flushed**: the bundle logs a warning with the event class and triggers a deprecation. Before 1.27 they were always
flushed. Flush your listener's own changes and set the option to `false`; 2.0 never flushes an application manager.

### Standard host

Nothing to configure. The bundle registers its attribute mapping on your default entity manager; the `uptime_*`
tables stay on that manager's connection (no data moves on upgrade). Optional:

```yaml
nowo_uptime_monitor:
    doctrine:
        connection: uptime          # rule 8: another DBAL connection for the bundle tables (null = default manager's)
        mapping_type: xml           # register the shipped XML mapping instead of the attributes (same schema)
        flush_listener_changes: false
```

`doctrine.connection` moves where the bundle reads and writes; create the tables there first
(`nowo:uptime:sync-schema` targets the same connection) and copy the rows yourself if you had data on the old one. The
repository services and `TenantProvisionerInterface::ensure()` read through your manager that maps the bundle
entities: map them on a manager that uses that same connection (see the DDD host below), or those reads see the other
database.

### DDD host

Keep the bundle out of your domain's entity manager and map it on a dedicated one, from the shipped XML:

```yaml
doctrine:
    dbal:
        connections:
            default: { url: '%env(DATABASE_URL)%' }
            uptime:  { url: '%env(UPTIME_DATABASE_URL)%' }   # or the same URL: a separate connection object is enough
    orm:
        default_entity_manager: default
        entity_managers:
            default:
                connection: default
                mappings:
                    App: { type: xml, is_bundle: false, dir: '%kernel.project_dir%/config/doctrine', prefix: 'App\Domain' }
            uptime:
                connection: uptime
                mappings:
                    Uptime:
                        type: xml
                        is_bundle: false
                        dir: '%kernel.project_dir%/vendor/nowo-tech/uptime-monitor-bundle/src/Resources/config/doctrine'
                        prefix: 'Nowo\UptimeMonitorBundle\Entity'

nowo_uptime_monitor:
    doctrine:
        register_mapping: false       # the bundle does not map itself on your default manager
        flush_listener_changes: false # the bundle never flushes one of your managers
```

Do not enable `auto_mapping` on your default manager: it would map the bundle entities there again. When your
default manager has neither `mappings` nor `auto_mapping` (DoctrineBundle 3.4 `auto_discover_entities` then claims
every entity class for it), also set `nowo_uptime_monitor.doctrine.entity_manager: uptime`: the bundle then keeps its
entities off the other managers (fixed in Unreleased). The bundle then
reads and writes on the `uptime` connection with that manager's configuration; your default manager and connection
are never touched. Reach the bundle only through its ports and scalar events (next section).

## Using it from a DDD / hexagonal application

**Layer.** The bundle is a bounded context of its own: it owns the `Tenant`, `Monitor`, `CheckResult`,
`CheckAggregate`, `Incident` and `Tag` entities and their tables, the scheduler, the admin UI, the REST API and the
status page. Treat it as an external monitoring subdomain. Your domain references it only by **tenant slug** (for
example your project id) and, when needed, by monitor id. Do not map or query its entities from your model.

**No coupling to your entities.** It has no relation to host entities, no `resolve_target_entities`, no mapped
superclass or trait to extend. The current user is passed to the access checker as `object`.

**Ports you implement (infrastructure adapters):**

| Port | Config key | Purpose |
|---|---|---|
| `Security\TenantAwareUptimeMonitorAccessCheckerInterface` (or `MonitorAwareUptimeMonitorAccessCheckerInterface`) | `security.access_checker` | Map your users and permissions to dashboard / manage / settings rights per tenant |
| `Limits\TenantLimitsInterface` | `limits.provider` | Per-tenant `maxMonitors()` / `minIntervalSeconds()` from your plans |
| `Tenant\MissingTenantProvisionerInterface` | `multi_tenant.provisioner` | Create a tenant on first access (usually delegates to `TenantProvisionerInterface::ensure()`) |
| `Ui\SettingsSectionsResolverInterface` | `web_ui.settings.sections_resolver` | Settings sections shown per tenant |
| `Notification\NotificationChannelInterface` | service tag `nowo.uptime_monitor.notification_channel` | Extra alert channel |

**Events to translate into your own domain events:**

- `Event\MonitorWentDownEvent` / `Event\MonitorRecoveredEvent`: an incident opened or resolved. Both are dispatched
  after the check is flushed (since 1.25), so the incident id is set. Read `snapshot()` (a `Model\IncidentSnapshot`:
  tenant slug, monitor id, name and type, incident id, trigger status, message, started / ended at) or the
  `getTenantSlug()` / `getMonitorId()` / `getIncidentId()` getters instead of the entities.
- `Event\MonitorCreatedEvent` / `MonitorUpdatedEvent` / `MonitorDeletedEvent` / `MonitorPausedEvent` (since 1.25):
  admin UI writes, after the commit. Scalars only: tenant slug, monitor id, actor id, `occurredAt`; the update carries
  the changed field names (never values), the pause its new `paused` state. An edit-form pause is a
  `MonitorUpdatedEvent` with `paused` in `changedFields`. A listener that throws after a check's commit is logged and
  does not stop the check.
- `Event\MonitorsAppliedEvent`: `nowo:uptime:apply-monitors` committed a tenant's monitors. Scalars only, dispatched
  after the commit.
- `Event\MonitorQuotaCheckEvent` (`UptimeEvents::MONITOR_QUOTA_CHECK`): cancellable check before an admin-UI create
  or resume. Call `deny()` and do not throw.

**Driving it from your application.** Type-hint the interfaces (since 1.25), both autowirable:
`Service\TenantProvisionerInterface` (`ensure($slug, $name)` / `setEnabled($slug, false)`) for tenants, and
`Apply\MonitorApplierInterface::apply()` (the engine of the declarative `nowo:uptime:apply-monitors` file) for
monitors. Wrap these calls behind your own port; in tests, replace the interface with a fake.

**Example anti-corruption listener:**

```php
use Nowo\UptimeMonitorBundle\Event\MonitorWentDownEvent;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

final readonly class UptimeIncidentTranslator
{
    public function __construct(private ProjectAlerts $alerts) {} // your domain port

    #[AsEventListener]
    public function onDown(MonitorWentDownEvent $event): void
    {
        // Copy scalars out; never keep the bundle entities in your domain.
        $incident = $event->snapshot();
        $this->alerts->serviceDown(
            projectId: $incident->tenantSlug,
            incidentId: (int) $incident->incidentId,
            checkName: $incident->monitorName,
            reason: $incident->message,
            since: $incident->startedAt,
        );
    }
}
```

**Known limitations:**

- There is no fine-grained programmatic API for single monitors yet (upsert / remove one monitor). Use the apply
  file or `MonitorApplierInterface`.
- The settings backup import (`MonitorBackupService::import()`) dispatches no per-monitor lifecycle events.
- `TenantProvisionerInterface::ensure()` returns the `Tenant` entity (of the application manager that maps the bundle
  entities, since 1.27.0); read `getSlug()` and drop it.
- `MissingTenantProvisionerInterface` returns a `Tenant` entity, and `canViewMonitor()` receives a `Monitor` entity.
- Custom notification channels must be tagged by hand.

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

Template overrides and the demo **must not** reintroduce raw `<form>`, `<input>`, `<textarea>` or `<select>` markup; `make check-no-raw-html-form` (part of `release-check`) fails when they do. The form variables each view receives are listed in [UPGRADING](UPGRADING.md#to-1250).

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
