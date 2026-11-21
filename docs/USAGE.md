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
- [Monitor change audit (1.33)](#monitor-change-audit-133)
- [SearchKit: monitors (1.32)](#searchkit-monitors-132)
- [Database and entity manager](#database-and-entity-manager)
  - [Standard host](#standard-host)
  - [DDD host](#ddd-host)
- [Using it from a DDD / hexagonal application](#using-it-from-a-ddd--hexagonal-application)
- [Cross-tenant reads and ports (1.35)](#cross-tenant-reads-and-ports-135)
  - [Report reads (1.36)](#report-reads-136)
- [Overriding templates](#overriding-templates)
  - [Monitor page by configuration (1.30)](#monitor-page-by-configuration-130)
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
| `nowo:uptime:purge-monitor-changes` | (1.33) Delete monitor change history older than `audit.history.retention_days`; `--anonymise-actor=<id>` replaces that actor identifier in every row instead (GDPR erasure) |
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

## Monitor change audit (1.33)

Off by default. With `audit.enabled: true` the bundle records every create, change and delete of a monitor, whatever
the write path: the new and edit forms, delete, pause / resume, push token issue / revoke, the backup import,
`nowo:uptime:apply-monitors`, `nowo:uptime:seed-demo` and host code that writes monitors through the bundle services.
Each change dispatches one `MonitorChangedEvent` (`UptimeEvents::MONITOR_CHANGED`) **after the commit**:

| Property | Content |
|----------|---------|
| `tenantSlug`, `monitorId` | The monitor (the id stays meaningful after a delete) |
| `action` | `created`, `updated` or `deleted` |
| `changes` | `list<MonitorFieldChange>`: `field` plus `before` / `after`, or `secret` = `replaced` / `cleared` for a write-only field. `changesAsArrays()` / `changedFields()` give plain arrays / names. |
| `actorId` | Who: the `audit.actor_resolver` result (default: the security token's user identifier; null in console commands and scheduled jobs) |
| `source` | Write path: `ui`, `import`, `apply`, `demo` or `app` (host code, other commands) |
| `occurredAt` | When the change was written |

```php
#[AsEventListener]
public function onMonitorChanged(MonitorChangedEvent $event): void
{
    foreach ($event->changes as $change) {
        if (in_array($change->field, ['type', 'proxy', 'ignore_tls'], true)) {
            $this->alerts->routeChanged($event->tenantSlug, $event->monitorId, $change->toArray(), $event->actorId);
        }
    }
}
```

Recorded fields: `name`, `type`, `target` and `proxy` (origin only: `scheme://host[:port]`), `ignore_tls`, `enabled`,
`interval_seconds`, `project`, `parent_id`, `origin`, `origin_key`, and the route, schedule and alerting settings
(`method`, timeouts, `max_redirects`, `expected_status_codes`, `keyword`, `upside_down`, `check_cert_expiry`,
`resend_notification_after_down`, `retries`, `retry_interval_seconds`, DNS / SSL / push options, `tags`, `description`).
Write-only fields (`target_details`, `proxy_details`, `headers`, `body`, `auth_username`, `auth_password`,
`push_url_key`, `push_token`, any unknown config key as `config.<key>`) carry only `replaced` or `cleared`. An update
that only moves the check state (next check, failure streaks, push samples) records nothing. Details in
[SECURITY](SECURITY.md#monitor-change-audit-133).

**Delivery rules.** The event never fires for a refused or rolled-back write. A unit of work that is replayed after a
deadlock dispatches once, with the committed attempt's values. When the bundle's write runs inside a transaction the
application opened (a write unit of nowo-tech/transaction-doctrine around a bundle route or message, or a host
transaction around a bundle service), the event waits until that transaction ends and is dispatched at the end of the
request, message or command only if it committed:

- **with the history on**, the bundle looks its history rows up by id: they exist only if the transaction committed.
  This is exact;
- **without the history**, it needs both no exception in the unit since the change (sticky) and, when
  NowoTransactionDoctrineBundle registers its commit ledger, a commit since the change. **Remaining case:** a host that
  rolls its transaction back, *catches* the failure and then commits something else in the same unit still gets the
  event of the rolled-back change. Without the commit ledger (NowoTransactionDoctrineBundle not registered, or its `commit_detection` off) and with the history off, a host transaction rolled back and caught is enough on its own to release a phantom event. Turn the history on, or commit detection on.

  When a write unit replays, changes waiting without the history and without the ledger are dropped (nothing can prove
  an earlier unit committed them).

A change still inside a transaction when the request, message or command ends is dropped with a warning. When a write
unit replays its work, the changes an earlier unit of the request already committed are kept. A listener that throws
is logged (class only) and does not fail the request: the change is already committed.

**History (optional).** With `audit.history.enabled: true` each change is also written to `uptime_monitor_change` in the
same transaction (a failing insert fails the change, so nothing is changed unaudited), and the monitor page shows the
latest `audit.history.display_limit` changes in a read-only "Change history" card, to whoever may view the monitor.
Create the table first ([UPGRADING](UPGRADING.md#to-1330)). Schedule `nowo:uptime:purge-monitor-changes` (daily, for
instance) to apply `audit.history.retention_days`; it also works with the history turned off, so old rows still age out.

**Personal data.** The actor identifier is personal data. Record a pseudonymous id rather than a login e-mail with
`audit.actor_resolver`:

```php
final readonly class AuditActor implements MonitorChangeActorResolverInterface
{
    public function __construct(private Security $security) {}

    public function actorId(): ?string
    {
        $user = $this->security->getUser();

        return $user instanceof User ? $user->getUuid() : null;
    }
}
```

and on an erasure request run `nowo:uptime:purge-monitor-changes --anonymise-actor=<id>` (or call
`MonitorChangeHistory::anonymiseActor()`). It also matches the `switch_user` forms `<id> (as …)` and `… (as <id>)`
(the whole actor of such a row is replaced, the other user included); `%` and `_` in the identifier match literally. Event listeners that store the actor are covered by the host's own policy.

## SearchKit: monitors (1.32)

With `nowo-tech/search-kit-bundle` ^1.9 installed (optional, `suggest`; **SearchKit needs PHP 8.3 or later**, the bundle itself still runs on PHP 8.2 without it) and `web_ui` enabled, the SearchKit palette and
results page get a group `uptime_monitors`. Each search:

1. takes the viewer's tenants from the tenant resolver (the `tenantSlug` of the request, else `multi_tenant.default_tenant`;
   a host replaces it with `search_kit.tenant_resolver`: since 1.34 a `MonitorSearchTenantsResolverInterface` returning a
   **list** of tenant slugs, or a 1.32 `MonitorSearchTenantResolverInterface` returning one; an empty list or `null`
   means no results). The search covers the union of the list. Keys are compared exactly: a key that is not a valid
   slug (lowercase letters, digits, dashes) matches nothing;
2. (1.34) dispatches `MonitorSearchScopeEvent` (`UptimeEvents::MONITOR_SEARCH_SCOPE`): a listener can only narrow
   (`onlyTenants([...])`, `excludeTenants([...])`, `deny()`), and applies the scope of the search box: the bundle's own
   scope `tenant:<slug>` keeps that tenant if it is in the list; any other scope (a project chip) counts only when a
   listener narrows for it and calls `acceptScope()`, else the search finds nothing. A scope never adds a tenant;
3. (1.34) checks the **search permission** per tenant, separate from the dashboard: the checker's
   `canSearch($user, $tenantSlug)` when it implements `SearchAwareUptimeMonitorAccessCheckerInterface` (the bundle's
   configurable checker answers with the dashboard roles, as in 1.32; the allow-all one with yes), then
   `MonitorSearchAccessEvent` (`UptimeEvents::MONITOR_SEARCH_ACCESS`: `grant()` / `deny()`, a denial wins). **Without
   either, search is denied.** Up to 1.32 the gate was `canAccessDashboard()`;
4. reads at most `search_kit.max_candidates` monitors of the remaining tenants in one SQL query (`t.slug IN (...)`
   applied before the limit, so rows of other tenants never take places in the window), matching every word against the
   **name** with an escaped `LIKE ... ESCAPE '!'` (the stored target may hold credentials, so SQL never matches it);
5. keeps only the monitors the checker lets the viewer see: `canViewMonitor()` for a monitor-aware checker (so a monitor shared
   outside the tenant is searchable), else `canAccessTenant()`, else the dashboard check; then SearchKit sends its
   `SearchKitEvents::ITEM_VISIBILITY` event, where a host deny wins.

**Multi-tenant hosts must set `search_kit.tenant_resolver`.** The palette request carries no `{tenantSlug}`, so the default (the request's `tenantSlug`, else `multi_tenant.default_tenant`) searches one tenant; a host with several tenants returns the viewer's own (since 1.34 all of them, as a list).

A host whose monitors live under its own pages, with no bundle dashboard (one tenant per project, `canAccessDashboard()`
false for everyone), grants search and lists the viewer's projects:

```php
final readonly class ProjectMonitorTenants implements MonitorSearchTenantsResolverInterface
{
    public function __construct(private ProjectMemberships $memberships) {}

    /** @return list<string> */
    public function resolveTenants(string $userIdentifier): array
    {
        return $this->memberships->projectSlugsOf($userIdentifier); // the tenant slugs of the viewer's projects
    }
}

#[AsEventListener]
public function onMonitorSearchAccess(MonitorSearchAccessEvent $event): void
{
    if ($this->projects->canUse($event->user, $event->tenantSlug, 'uptime')) {
        $event->grant();
    }
}

#[AsEventListener]
public function onMonitorSearchScope(MonitorSearchScopeEvent $event): void
{
    $scope = $event->getScope();                 // client input, e.g. "project:<uuid>" from a project chip
    if ($scope !== null && str_starts_with($scope, 'project:')) {
        $event->onlyTenants([substr($scope, 8)]); // narrows only: a tenant outside the resolver's list stays out
        $event->acceptScope();
    }
}
```

Every row is still checked with `canViewMonitor()` (else the tenant / dashboard check) and SearchKit's host event.

The resolver, `MonitorSearchScopeEvent` and `MonitorSearchAccessEvent` may run more than once per search (once to name
the SearchKit tenant, once for the query, and again for each revalidated recent): keep them side-effect free and
deterministic; if their answers differ between the two calls the search finds nothing. The tenant SearchKit passes to
`SearchItemVisibilityEvent` is the slug for one tenant and `tenants:<sha256 of the sorted slugs>` for several: it never
means "all tenants". Under `security.allow_unauthenticated` the search permission is not checked and
`MonitorSearchAccessEvent` is not dispatched, so a listener's `deny()` has no effect there. **Results are ordered by monitor name** (then id), with no relevance ranking.

A refusal anywhere (no tenant, no access, a denied monitor) gives no group and no count, so a hidden monitor looks like a
missing one. The total is the visible count inside the candidate window and the results page goes at most that deep.

**What a result shows:** the name, the link to the monitor page, the origin of the target and the translated last status
(`Paused` for a paused monitor, whose last status is stale). The status is shown because the viewer may see the monitor. It
never shows the config, headers, tokens, auth settings or push credentials. There is no "see all" link: no page of the bundle
lists monitors by a search text.

**URLs:** only the origin is shown (`https://host:8443`, `db.internal:5432`): userinfo, path, query string and fragment are
dropped, because a secret may sit in any of them. It fails closed: a target that is not a plain URL or host (a connection
string such as `Server=db;Password=x;`) shows nothing, and an ambiguous URL shows `scheme://***` with no host. A URL is
ambiguous when an `@` follows the first `/`, `?` or `#` and either the authority before it holds an `@` or a `:` stands
before that `@` (`https://admin:4242/rest@host/`, `https://host:8443/n?to=ops@example.com`): a password with a raw `/`, `?`
or `#` looks exactly like that. Push monitors and groups show no URL.

**Recents:** SearchKit's recents keep the title and subtitle of the rows a viewer opened in the browser (`localStorage`). A
monitor row's subtitle holds the origin of its target and its status. With `recent.revalidate: false` they stay there
without being checked again, so a viewer who lost access still sees them on that browser; keep recents revalidated (the
definition supports it: `idField()` / `referenceId()`) or off if origins must not be stored.

```yaml
nowo_uptime_monitor:
    search_kit:
        enabled: true            # false: no monitors in SearchKit
        tenant_resolver: null    # service id of a MonitorSearchTenantsResolverInterface (1.34, a list) or MonitorSearchTenantResolverInterface
        max_candidates: 100      # 1-1000
```

The group can also be switched off or reordered in SearchKit (`nowo_search_kit.groups.uptime_monitors`). The services are
stateless and read the user and the request at call time, so they are safe in FrankenPHP worker mode with kernel reset off.

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
| `Share\MonitorSharePolicyInterface` (1.35) | `shares.policy` | Who a monitor may be shared with (deny by default) |

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
- `MissingTenantProvisionerInterface` returns a `Tenant` entity. `canViewMonitor()` receives a `Monitor` entity; since
  1.35 a checker can implement `canViewMonitorId()` instead (see below).
- Custom notification channels must be tagged by hand.

## Cross-tenant reads and ports (1.35)

Ports for hosts that list or count monitors across tenants, or that keep no bundle entity in their own code. All of
them are autowirable interfaces; none returns or receives a host entity.

**Monitor lists and counts: `Query\MonitorQueryInterface`.** Read models (`MonitorView`, `LatestCheckView`,
`MonitorCounts`), never entities. The caller decides which tenants the user may read and passes them as slugs; the
bundle reads nothing else. An empty scope answers nothing without a query.

```php
use Nowo\UptimeMonitorBundle\Query\MonitorListCriteria;
use Nowo\UptimeMonitorBundle\Query\MonitorListSort;
use Nowo\UptimeMonitorBundle\Query\MonitorListStatus;
use Nowo\UptimeMonitorBundle\Query\MonitorQueryInterface;
use Nowo\UptimeMonitorBundle\Query\MonitorRef;

// Dashboard: the user's tenants, plus single monitors shared with them (each bound to its own tenant).
$criteria = new MonitorListCriteria(
    tenantSlugs: $allowedSlugs,
    grantedMonitors: [new MonitorRef('user-42', 17)],
    type: null,                          // or a MonitorType
    status: MonitorListStatus::Down,     // up | down | degraded | unknown | paused; null = any
    paused: null,                        // true / false narrows further
);
$total = $query->count($criteria);
$rows  = $query->list($criteria, MonitorListSort::Problem, descending: false, limit: 25, offset: 0);
foreach ($rows as $view) {
    // $view->id, tenantSlug, tenantName, name, type, target (masked), intervalSeconds, paused, lastKnownStatus,
    // displayStatus(), $view->latest?->checkedAt / latencyMs / status / statusCode
}

$usage = $query->countActive($payerSlugs);   // ->monitors (not paused, groups left out), ->pushMonitors
$down  = $query->countDown($projectSlugs);   // summary card
$one   = $query->findInTenant('acme', 17);   // MonitorView|null, exact slug
```

The problem sort is down, degraded, never checked / unknown, up, then paused; every sort breaks ties by name, then id.
`limit` is clamped to 1-500.

**Many tenants at once: `Service\BulkTenantProvisionerInterface`.** `setEnabledMany(['p-1', 'p-2'], false)` runs one
idempotent `UPDATE` (plan changes, feature switches). Unknown slugs are ignored.

**Monitor by tenant: `Repository\MonitorRepositoryInterface::findInTenant($slug, $id)`** returns the entity (application
manager, like the repository service) only when the monitor belongs to that tenant. Prefer
`MonitorQueryInterface::findInTenant()` for plain values.

**Access by id: `Security\MonitorIdAwareUptimeMonitorAccessCheckerInterface`.** Implement `canViewMonitorId($user,
$tenantSlug, $monitorId)` next to `canViewMonitor()`; the bundle then asks the id form for the reads of one monitor. It
has already checked that the monitor belongs to `$tenantSlug`.

**Quota listeners:** read `$event->monitorId` and `$event->paused` of `MonitorQuotaCheckEvent`; `$event->monitor` is
deprecated.

**Backup export by slug:** `MonitorBackupService::export('acme')`.

**Chart series:** `nowo_uptime_chart_series(chart_series_json, 'uptime_percent', 'uptime', 'Uptime'|trans)` in Twig, or
`ChartSeriesFormatter::format()` in PHP, returns `{labels, series: [{key, label, data}], total}` (decimals rounded to
two places, gaps as `null`, `total` = points with a value).

### Report reads (1.36)

Two read ports for reports (an uptime report per project, an SLA page) so a host never writes DQL or SQL over the
bundle's `CheckAggregate` and `Incident` entities. Both take a `Query\MonitorScope` and a window, and return read
models and numbers, never entities.

```php
use Nowo\UptimeMonitorBundle\Enum\AggregatePeriod;
use Nowo\UptimeMonitorBundle\Query\CheckAggregateQueryInterface;
use Nowo\UptimeMonitorBundle\Query\IncidentQueryInterface;
use Nowo\UptimeMonitorBundle\Query\MonitorRef;
use Nowo\UptimeMonitorBundle\Query\MonitorScope;

// Every monitor of the tenants the user may read; optionally only some monitor ids, plus single shared monitors.
$scope = MonitorScope::tenants($allowedSlugs);
$scope = new MonitorScope($allowedSlugs, [new MonitorRef('user-42', 17)], monitorIds: [3, 17]);

// Rollups: the window is half-open, a bucket counts when $from <= periodStart < $to (daily buckets by default).
foreach ($aggregates->totalsByMonitor($scope, $from, $to) as $monitorId => $totals) {
    // $totals->tenantSlug, buckets, checksTotal, checksUp, checksDown, latencyWeightedMs,
    // $totals->uptimeRatio() (0-1, null without checks), $totals->latencyAvgMs() (weighted by checks)
}
$page  = $aggregates->list($scope, $from, $to, AggregatePeriod::Hour, limit: 500, offset: 0); // CheckAggregateView, oldest first
$total = $aggregates->count($scope, $from, $to, AggregatePeriod::Hour);

// Incidents overlapping the window (started before $to, open or ended at $from or later).
$downtime = $incidents->downtimeByMonitor($scope, $from, $to, $clock->now()); // [monitorId => IncidentDowntime(incidents, seconds)]
$latest   = $incidents->list($scope, $from, $to, open: null, limit: 100);      // IncidentView, newest first
$open     = $incidents->count($scope, $from, $to, open: true);
```

- **Scope.** The caller decides which tenants (and single monitors) the user may read; the bundle reads nothing else.
  An empty scope, an empty id filter or an empty window (`$from >= $to`) answers nothing without a query. Invalid slugs
  and ids below 1 are dropped. Group monitors are included.
- **Access.** The read ports do not decide access: a host that denies access per monitor (shares, roles) must narrow
  `monitorIds` itself.
- **Size of the scope.** Each of `tenantSlugs` and `monitorIds` is one `IN` list in one statement: keep each to a few
  thousand entries per call (MySQL, MariaDB and PostgreSQL allow 65,535 placeholders per statement; SQLite before 3.32
  allows 999 and fails above). Split larger scopes into several calls.
- **Exact tenants.** Each row carries its tenant slug and is compared with the scope again in PHP (`===`): a tenant
  whose slug differs only by case or accents, which a case-insensitive collation would match, is never read.
- **Pages.** `list()` pages are stable (ties broken by monitor id and row id); `limit` is clamped to
  `CheckAggregateQueryInterface::MAX_LIMIT` (1,000) and `IncidentQueryInterface::MAX_LIMIT` (2,000), `offset` to 0 or
  more. A page can be shorter than `limit` when rows of look-alike tenants are dropped by the PHP re-check, so page until
  an empty page, never until `offset >= count()`. Use `count()` for the total and `downtimeByMonitor()` for whole totals (it sums every incident, no page cap).
- **Dates.** Pass the window in the time zone the bundle writes (the PHP default time zone). An open incident runs
  until the `$now` you pass, never past `$to`, and never counts negative time.
- **Messages.** `IncidentView::$message` has its URLs masked (user info and secret-looking query values).
- **Platforms.** `downtimeByMonitor()` sums in native SQL on MySQL / MariaDB, PostgreSQL and SQLite (one statement
  per 1,000 monitors); other platforms get a `LogicException` before any query runs. The rest is DQL.
- **Concurrency and worker mode.** Plain reads on the bundle's own manager: no lock, so a report never waits for a
  rollup or a check and never sees uncommitted rows; nothing stays managed; the services are stateless `readonly`
  classes, safe in a kept-alive FrankenPHP worker (no reset needed).

**UI hook events by id (1.36).** `StatusPageMonitorsEvent::getMonitorIds()` / `keepMonitorIds()`,
`MonitorShowLiveUpdatesEvent::getMonitorId()`, `UptimePageEvent::getMonitorId()` (null on the dashboard), each with
`getTenantSlug()` and `getOccurredAt()`. The entity accessors still work and are deprecated (docblock only, removed in
2.0).

### Monitor shares

With `shares.enabled: true` the bundle stores which host users may read a monitor outside its tenant: one row per monitor
and user identifier (your own id, 1-128 characters, compared exactly). Deciding who may read a monitor stays in your
checker. Typically `canViewMonitorId()` returns `canAccessTenant($user, $slug) || $shares->isSharedWith($slug, $id,
$user->getUserIdentifier())`.

```php
use Nowo\UptimeMonitorBundle\Share\MonitorSharePolicyInterface;
use Nowo\UptimeMonitorBundle\Share\MonitorSharesInterface;
use Nowo\UptimeMonitorBundle\Share\MonitorShareRefusedException;

final readonly class CoMemberSharePolicy implements MonitorSharePolicyInterface // shares.policy: this service
{
    public function canShareWith(string $tenantSlug, int $monitorId, string $userIdentifier): bool
    {
        return $this->coMembers->shareAProject($tenantSlug, $userIdentifier); // your rule
    }
}

$share = $shares->create('user-42', 17, 'user-7');  // MonitorShareView; ->created false when it already existed
$shares->delete('user-42', 17, 'user-7');           // bool, idempotent
$shares->usersOf('user-42', 17);                    // list<string>
$shares->sharedWith('user-7');                      // list<MonitorShareView> (->monitorRef() for MonitorListCriteria)
$shares->deleteAllForUser('user-7');                // account erasure
```

`create()` throws `MonitorShareRefusedException` (`reason`: `monitor_not_found`, `denied`, `invalid_user`,
`identifier_collision`) when it writes nothing (`identifier_collision`: see [CONFIGURATION](CONFIGURATION.md#monitor-shares-135)). A double submit, or two concurrent ones, gives one row and the same answer: the existing share comes
back with `created` false. A lock conflict throws the runner's `TransientStorageFailure`, which your conflict answer
(nowo-tech/transaction-doctrine's responder) turns into 409 + `Retry-After` + `CONFLICT_001`, or `CONFLICT_002` when
the request already committed other work. See [CONCURRENCY](CONCURRENCY.md).

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

### Monitor page by configuration (1.30)

The monitor page (`monitor_show`) can be laid out without a template copy. Every option is off by default, and the
page is then byte for byte the 1.29 one (pinned by a test against the v1.29.0 template).

```yaml
# config/packages/nowo_uptime_monitor.yaml
nowo_uptime_monitor:
    web_ui:
        monitor_show:
            # Regions inside the live-updates root, in this order (the others follow in the bundle order).
            order: [figures, history, details, children, charts, push, log]
            charts: host
            slots:
                figures: 'uptime/monitor/_figures.html.twig'   # e.g. a UiKit stat_row
                details: 'uptime/monitor/_details.html.twig'   # e.g. a compact detail_list
                charts: 'uptime/monitor/_charts.html.twig'
                # history / log: replace those regions too (log gets log_events, log_pagination, log_sort)
            log:
                sortable: true                     # headers sort the recent check log, last 100 checks (log_sort / log_dir)
                align_numbers: true                # latency and code at the end (class uptime-cell-num)
                time_format: 'H:i'
                time_title_format: 'Y-m-d H:i:s'   # full date and seconds in the title attribute
                per_page: 25                       # null = web_ui.per_page
            header:
                actions_layout: split              # icon buttons, one primary button, the "more" menu
                status: true                       # live status badge next to the title (UiKit page_status)
                icons: { edit: 'tabler:pencil', pause: 'tabler:player-pause', resume: 'tabler:player-play' }
```

The page header is the UiKit `page_header` partial, so a host theme override of that partial applies. Host header
actions still come from `UptimePageEvent::addAction()` (give them `display: icon` to sit with the icon buttons).

**Live updates per request.** `MonitorShowLiveUpdatesEvent` is dispatched on every monitor page render. A listener can
turn the history polling off (`setApiHistoryUrl(null)`: no `data-api-history`, the script keeps the server-rendered
state) and add attributes to the script root `#uptime-monitor-detail-root`. Only `data-*`, `aria-*`, `role`, `title`,
`lang`, `dir` and `class` (appended) are accepted, never `on*`, `style` or the bundle's own attributes. Every value is
escaped. The event lives for one request, so decide from the current user or request in the listener and keep no state
in the service (FrankenPHP worker mode).

```php
#[AsEventListener]
public function onMonitorPage(MonitorShowLiveUpdatesEvent $event): void
{
    $mode = $this->preferences->liveUpdates();   // auto | notify | paused, read for this request
    if ($mode === 'auto') {
        return;
    }
    $event->setApiHistoryUrl(null);
    if ($mode === 'notify') {
        $event->setRootAttribute('data-controller', 'live-updates-watch');
        $event->setRootAttribute('data-live-updates-watch-url-value', $event->historyUrl);
        $event->setRootAttribute('data-live-updates-watch-interval-value', 15000);
    }
}
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
