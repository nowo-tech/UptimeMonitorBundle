# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/).

## Table of contents

- [[Unreleased]](#unreleased)
- [[1.12.1] - 2026-10-27](#1121---2026-10-27)
- [[1.12.0] - 2026-10-27](#1120---2026-10-27)
- [[1.11.0] - 2026-10-27](#1110---2026-10-27)
- [[1.10.0] - 2026-10-27](#1100---2026-10-27)
- [[1.9.4] - 2026-10-26](#194---2026-10-26)
- [[1.9.3] - 2026-10-26](#193---2026-10-26)
- [[1.9.2] - 2026-10-26](#192---2026-10-26)
- [[1.9.1] - 2026-10-26](#191---2026-10-26)
- [[1.9.0] - 2026-10-26](#190---2026-10-26)
- [[1.8.0] - 2026-10-26](#180---2026-10-26)
- [[1.7.4] - 2026-10-25](#174---2026-10-25)
- [[1.7.3] - 2026-10-24](#173---2026-10-24)
- [[1.7.2] - 2026-10-20](#172---2026-10-20)
- [[1.7.1] - 2026-10-18](#171---2026-10-18)
- [[1.7.0] - 2026-10-14](#170---2026-10-14)
- [[1.6.1] - 2026-10-13](#161---2026-10-13)
- [[1.6.0] - 2026-10-13](#160---2026-10-13)
- [[1.5.0] - 2026-10-13](#150---2026-10-13)
- [[1.4.0] - 2026-10-13](#140---2026-10-13)
- [[1.3.11] - 2026-10-13](#1311---2026-10-13)
- [[1.3.10] - 2026-10-09](#1310---2026-10-09)
- [[1.3.9] - 2026-09-27](#139---2026-09-27)
- [[1.3.8] - 2026-09-07](#138---2026-09-07)
- [[1.3.7] - 2026-08-24](#137---2026-08-24)
- [[1.3.6] - 2026-08-20](#136---2026-08-20)
- [[1.3.5] - 2026-08-19](#135---2026-08-19)
- [[1.3.4] - 2026-08-19](#134---2026-08-19)
- [[1.3.3] - 2026-08-19](#133---2026-08-19)
- [[1.3.2] - 2026-08-19](#132---2026-08-19)
- [[1.3.1] - 2026-08-18](#131---2026-08-18)
- [[1.3.0] - 2026-08-04](#130---2026-08-04)
- [[1.2.0] - 2026-08-03](#120---2026-08-03)
- [[1.1.1] - 2026-07-30](#111---2026-07-30)
- [[1.1.0] - 2026-07-30](#110---2026-07-30)
- [[1.0.11] - 2026-07-29](#1011---2026-07-29)
- [[1.0.10] - 2026-07-22](#1010---2026-07-22)
- [[1.0.9] - 2026-07-22](#109---2026-07-22)
- [[1.0.8] - 2026-07-16](#108---2026-07-16)
- [[1.0.7] - 2026-07-13](#107---2026-07-13)
- [[1.0.6] - 2026-07-13](#106---2026-07-13)
- [[1.0.5] - 2026-07-08](#105---2026-07-08)
- [[1.0.4] - 2026-07-08](#104---2026-07-08)
- [[1.0.3] - 2026-07-06](#103---2026-07-06)
- [[1.0.2] - 2026-07-06](#102---2026-07-06)
- [[1.0.1] - 2026-07-06](#101---2026-07-06)
- [[1.0.0] - 2026-07-06](#100---2026-07-06)

## [Unreleased]

## [1.12.1] - 2026-10-27

UiKit ^2.15.4 (workarounds removed), real deprecation gate; SEC-004 1.12 delta: empty role lists deny, tenant quota on import/edit/concurrent creates, status page re-filter, URL validation, masked URLs in check messages See [UPGRADING](UPGRADING.md#to-1121).

### Security

SEC-004 delta re-audit of 1.12.x (`af6692d`): Pass (good) / Low; its findings are fixed. See
[SECURITY](SECURITY.md#ai-security-audit) and [UPGRADING](UPGRADING.md#unreleased).

- **Empty role lists deny (P1)**: the built-in access checker no longer treats an empty list as "everyone". An area is
  allowed when the user holds a role of each non-empty list among `security.access_roles` and the area's list; both
  empty = nobody. `access_roles` and every `security.roles.<action>` list empty, with the web UI on and no
  `security.access_checker`, fails the container compile.
- **Backup import honours the tenant limits (Q1)**: monitors beyond the remaining `limits.max_monitors` quota (groups
  not counted; overwriting a group into a check takes a slot) are skipped and reported as `over_quota` (new key of
  `MonitorBackupService::import()`'s result, warning flash); imported and overwritten checks get at least
  `limits.min_interval_seconds`.
- **Group → check needs a free slot (Q2)**: editing a group monitor into a check is refused with a form error when the
  tenant is full.
- **No count-then-insert race (Q3)**: New monitor, a group → check edit and the backup import write under
  `TenantQuota::withTenantLock()` (one transaction, PESSIMISTIC_WRITE lock on the tenant row, quota counted again after
  the lock).
- **Status page event (I1)**: monitors a `StatusPageMonitorsEvent` listener sets are filtered again (same tenant, not
  paused): listeners narrow the list only.
- **Monitor URL (I2)**: HTTP(S) monitors need a full `http://` / `https://` URL with a host (`form.monitor.url_invalid`,
  backup rows included).
- **Check messages (I3)**: URLs inside stored check messages and metadata are masked like the dashboard targets
  (`SecretRedactor::maskUrlsInText()`).

### Changed

- **UiKit 2.15.4**: `nowo-tech/ui-kit-bundle` constraint raised to `^2.15.4` (root and demo locks). UiKit now spaces
  Bootstrap form rows (`mb-3`), gives file inputs the `input` slot (`form-control`), sets `data-bs-theme` with
  `data-theme` and prints the back link label once with `icon_set: none`, so the workarounds of 1.11.0 are gone: the
  demo host layout no longer mirrors `data-theme` into `data-bs-theme`, and the bundle CSS spaces only the rows of its
  own fieldsets that carry no UiKit row class (other stacks than Bootstrap), not every form row.
- **URL fields** (`url` of the monitor form, `primaryBaseUrl` of Settings → General) set `default_protocol: null` on
  every Symfony version: the value is kept as typed and the field renders as `type="url"`. On Symfony 7.4 a URL typed
  without a scheme was prefixed with `http://` (and the deprecated implicit default of symfony/form 7.1 was
  triggered); Symfony 8 already behaved like this. See [UPGRADING](UPGRADING.md#unreleased).
- **Deprecation gate** (REQ-SF-005): PHPUnit fails on any deprecation, silenced `trigger_deprecation()` calls
  included (`failOnDeprecation`, `failOnPhpunitDeprecation`, `ignoreSuppressionOfDeprecations` in
  `phpunit.xml.dist`; symfony/phpunit-bridge does nothing on PHPUnit ≥ 10). Tests of the deprecated 1.x keys assert
  their deprecations; the two Twig deprecations raised inside UiKit's `PageContractTestCase` are ignored for that one
  test ([#123](https://github.com/nowo-tech/UptimeMonitorBundle/issues/123)). `phpunit/phpunit` dev constraint
  `^11.5`.

### Fixed

- Spec Kit `code-inventory.md` lists the 1.12.0 table dashboard template and SCSS partial (REQ-SPECKIT-001); README
  coverage figures refreshed for 1.12.0 (4745/4745 lines, REQ-TEST-007).
- `dashboard/_table_view.html.twig` uses the `include()` function (Twig-CS-Fixer `make twig-lint` was failing).

## [1.12.0] - 2026-10-27

Host embedding: page actions by access, per-tenant limits, a table dashboard, a paginated check log and extension
events, so a host shows the bundle pages in its own navigation without copying templates. See
[UPGRADING](UPGRADING.md#to-1120).

### Added

- **Page actions follow the access checker** (`Ui\UptimeActions`): the dashboard's Settings / New monitor and a
  monitor's Edit only for users allowed to use them; a monitor page also offers **Pause / Resume** (back to the page)
  and **Delete** behind the UiKit confirm. `web_ui.dashboard.actions` chooses and orders the dashboard header actions.
- **Per-tenant limits** (`limits.max_monitors`, `limits.min_interval_seconds`, or a host
  `Limits\TenantLimitsInterface` as `limits.provider`): New monitor is disabled with the reason once the tenant is
  full, the form says why and refuses the monitor, and the interval field refuses checks more often than the minimum
  (groups excepted).
- **Table dashboard** (`web_ui.dashboard.view: table`): the monitors' state as counts, a UiKit responsive table
  (name, type, target with secrets masked, status — "not checked yet" until the first check —, 24 h uptime, last
  check, interval, row actions in one menu) and the latest checks newest first, paginated; the empty state offers New
  monitor. `web_ui.dashboard.header: monitors` titles the page "Monitors" for hosts that show the tenant around it.
- **Paginated check log** on the monitor page (`web_ui.per_page`, default 20) and a "not checked yet" notice in place
  of the empty history, figures and log until the first check (the page reveals them when it arrives).
- **Extension events**: `Event\UptimePageEvent` (dashboard and monitor page: header actions and host sections before
  or after the bundle content) and `Event\StatusPageMonitorsEvent` (the monitors a public status page shows; the
  overall status follows them).

### Fixed

- **Monitor form with hidden fields**: a field left out by `form.hidden_fields` (e.g. `project`) no longer breaks the
  create / edit page; the monitor page leaves out the project row then.
- **Monitor form errors**: root errors (tenant limit, expired form) are shown above the fields.

## [1.11.0] - 2026-10-27

UiKit page contract UI, 100% PHP/99% TS coverage gates, Playwright e2e + screenshots, table_prefix, clock injection, SEC-004 hardening (check limits, scan isolation, CSRF fail-closed, redaction) See [UPGRADING](UPGRADING.md#to-1110).

### Security

SEC-004 re-audit 2026-10-27 findings (M1, L1–L7 and Info items):

- **One monitor can no longer stall or exhaust the shared scan (M1)**: new `checks.max_timeout_seconds` (60),
  `checks.max_body_bytes` (1 MiB), `checks.max_redirects` (20) and `checks.max_scan_seconds` (300; 0 = no budget) caps
  (`Check\CheckLimits`); HTTP checks read the body only for a keyword and at most `max_body_bytes`; a runner that
  throws is stored as `unknown` and rescheduled, and the scan logs and skips a monitor whose execution fails. The
  monitor form refuses timeouts above the cap.
- **Unstorable check data no longer stops the scan (N1)**: check messages and metadata are scrubbed to valid, bounded
  UTF-8 (`CheckResultDto::sanitized()`, e.g. a DNS TXT record with invalid bytes); when a flush still closes the entity
  manager, `DueChecksRunner` resets it through the registry, reschedules the failing monitor with a DQL update and
  reloads the remaining monitors.
- **Redaction (N2)**: URL userinfo is masked up to the last `@` of the authority, credential-like query parameter
  values (`token`, `key`, `secret`, `sig`, …) are masked, and backup exports drop the request body.
- **Disabled admin UI (N3)**: `web_ui.enabled: false` no longer registers the admin / mutation routes, and the access
  subscriber answers 404 for those controllers if a host imports `routes.yaml` directly.
- **Fail-closed CSRF (L1, REQ-SEC-005)**: `CsrfProtectionPass` refuses to compile the container when the admin UI is
  enabled without form CSRF protection or a CSRF token manager.
- **Disabled tenants (L2)**: their monitors are no longer checked and their status page answers 404.
- **Certificate-expiry probe (L3)**: reads the certificate chain of the validated HTTP connection
  (`capture_peer_cert_chain`) instead of re-resolving the host — no DNS rebinding, proxy honoured.
- **Monitor proxies (L4)**: with `block_private_urls`, the proxy host is resolved once, validated and pinned to that IP.
- **Status page opt-in (L5)**: `status_page.default_public` (default `true`, 1.x behaviour) and a per-tenant
  *Public status page* setting (default / public / private); unknown, disabled and private tenants answer the same 404.
- **Tenant list (L6)**: a tenant-aware access checker filters `/tenants`.
- **Backups (L7)**: exports redact secrets (URL / proxy credentials, basic-auth password, custom header values);
  imports are capped (2 MB file, 1000 rows), look names up once, skip rows that fail the monitor constraints
  (`invalid` count) and show a generic error (exception class logged).
- **Info**: URL credentials (`user:pass@`) are masked in the dashboard, monitor page, alert e-mails and webhook payloads
  (`Security\SecretRedactor`); dashboard events escape status and time (`dashboard-panel.ts`); a monitor's parent group
  must belong to its tenant; `TenantProvisioner::ensure()` validates the slug; the unused *Steam API key* /
  *Chromium executable* settings were removed (dropped from the stored settings on the next save).
- **Logging (REQ-OBS-001 rule 4)**: `nowo:uptime:clear-data`, `nowo:uptime:purge-detail`, settings purge / clear
  statistics and backup export / import log start and success (tenant slug, counts) at `info` and failures at `error`
  with the exception class only.

### Added

- PHPDoc on every class and public method of `src/` (REQ-CS-001): an English summary, plus `@param` / `@return` /
  `@throws` where the native types need detail. `make validate-phpdoc` (`.scripts/complete-public-phpdoc.php`, read-only)
  fails on a missing docblock; `release-check` and the CI PHPStan job run it.
- Demo Playwright e2e (REQ-DEMO-013): `demo/symfony8/e2e` happy path (dashboard, monitor create + detail, public status page, settings) and a screenshots project; `make test-e2e` / `make demo-screenshots` (root and demo Makefiles, PNG → WebP with `sharp`). `release-check` runs `test-e2e`.
- README **Screenshots** section with `docs/images/demo/dashboard.webp` and `docs/images/demo/monitor-detail.webp`.
- Demo-only `app:uptime:demo-history` command: three extra local-probe monitors and 24 h of deterministic check history for every monitor and the group (one resolved outage, one latency spike); `reset-demo` runs it plus `nowo:uptime:rollup`.
- `web_ui` configuration (REQ-UI-003 vocabulary): `web_ui.enabled`, `web_ui.path_prefix`, `web_ui.tenant_paths`, `web_ui.layout_template`
  (null = UiKit layout), `web_ui.templates.<page>` (per-page override), `web_ui.form_themes`; `security.roles.dashboard
  | manage | settings`.
- `Ui\UptimeAreaNav`: the bundle navigation published to the UiKit area-nav registry (`uptime_monitor`,
  `uptime_monitor_settings`) for host menus.
- `docs/TEMPLATES.md` and the UiKit contract test `tests/Integration/Contract/PageContractTest.php` (every page).
- Translations for the monitor detail, tenant pages and tag delete confirmation (7 locales).
- TypeScript coverage gate (REQ-TEST-003 rule 3): Vitest tests for every dashboard module (polling, Mercure, layout
  refresh, events filter, sidebar, monitor dialog, monitor detail charts / history) — 99.08% lines, 93.63% branches;
  `vitest.config.ts` fails under 90% (lines, statements, functions, branches). `make test-ts` keeps the Vitest exit
  status (it was lost in a pipe), and CI runs a new **Frontend (Vitest, coverage gate)** job (install, coverage, build).
- Demo Playwright e2e (REQ-DEMO-013): `demo/symfony8/e2e` happy path (dashboard, monitor create + detail, public status page, settings) and a screenshots project; `make test-e2e` / `make demo-screenshots` (root and demo Makefiles, PNG → WebP with `sharp`). `release-check` runs `test-e2e`.
- README **Screenshots** section with `docs/images/demo/dashboard.webp` and `docs/images/demo/monitor-detail.webp`.
- Demo-only `app:uptime:demo-history` command: three extra local-probe monitors and 24 h of deterministic check history for every monitor and the group (one resolved outage, one latency spike); `reset-demo` runs it plus `nowo:uptime:rollup`.
- `web_ui` configuration (REQ-UI-003 vocabulary): `web_ui.enabled`, `web_ui.path_prefix`, `web_ui.tenant_paths`, `web_ui.layout_template`
  (null = UiKit layout), `web_ui.templates.<page>` (per-page override), `web_ui.form_themes`; `security.roles.dashboard
  | manage | settings`.
- `Ui\UptimeAreaNav`: the bundle navigation published to the UiKit area-nav registry (`uptime_monitor`,
  `uptime_monitor_settings`) for host menus.
- `docs/TEMPLATES.md` and the UiKit contract test `tests/Integration/Contract/PageContractTest.php` (every page).
- Translations for the monitor detail, tenant pages and tag delete confirmation (7 locales).

### Changed

- Demo README screenshots (REQ-DEMO-013): the full demo frame again — masthead, bundle UI and the Symfony web debug
  toolbar (light theme); `docs/images/demo/*.webp` refreshed.
- Root dependencies (lock, semver-safe): `nowo-tech/ui-kit-bundle` 2.15.3, `nowo-tech/form-kit-bundle` 2.13.3,
  `doctrine/doctrine-bundle` 2.19.2, `doctrine/inflector`, `masterminds/html5`; dev: `rector/rector` 2.8.1,
  `phpstan/phpstan` 2.3.2, `phpstan/phpstan-symfony` 2.1.1.
- Spec Kit baseline (REQ-SPECKIT-001/003): `specs/001-baseline/code-inventory.md` maps all 184 production files
  (socket connector, events, routing / tenant paths, form types, tenant provisioner, UI page contract); new
  `FR-CHECK-006`, `FR-EVENT-001`, `FR-SEC-002`, `FR-FORM-003`, `FR-UI-003/004`, `FR-ROUTE-001/002`, `FR-TENANT-001`.
- README: the demo is Symfony 8 only; translations ship 7 locales.
- **UiKit page contract (REQ-UI-003, REQ-UI-001)**: every admin page now extends a UiKit 2.15 page scaffold fed by
  UiKit view models built in the controllers — tenants (`page/list`), new tenant and monitor create / edit
  (`page/form`), dashboard (`page/dashboard`), monitor detail (`page/show`) and the eight settings screens
  (`page/settings`, one section tab per screen). The public status page is a declared unique page on the UiKit guest
  layout. Pages render inside `nowo_ui_kit.layouts.admin` / `.guest` (or `web_ui.layout_template`); markup uses
  `nowo-ui-*` classes and theme slots only (`nowo_ui_class()` / `nowo_ui_attrs()`), so `nowo_ui_kit.css_framework` and
  `nowo_ui_kit.theme` style every page. See [TEMPLATES.md](TEMPLATES.md).
- **Monitor dialog** is a native `<dialog>` (UiKit `dialog` partial) opened by `uptime-dashboard.js` /
  `uptime-monitor-detail.js` without Bootstrap JS; its openers are links to the form page (works without JavaScript).
- **Destructive actions** (Clear all statistics, delete tag) use the UiKit confirm dialog (`View\Action` +
  `View\Confirm`, POST + CSRF-only Form Types); the inline `onsubmit="return confirm(…)"` is gone (CSP). The monitor
  search form no longer has an inline `onsubmit` handler.
- **Bundle CSS** colours (`--uptime-*`) follow the UiKit `--nowo-ui-*` tokens first (then `--tblr-*`, then the bundle
  defaults); the per-tenant theme (Settings → Appearance) sets `data-theme` on the bundle widgets.
- **FormKit profile** seeded by the bundle (`nowo_form_kit.profiles.uptime_monitor`, when the host defines none): no
  Bootstrap classes, `convention_requires_translation: true` (no raw `*.help` / `*.placeholder` keys); the bundle
  forms use the UiKit form theme (`web_ui.form_themes`). Requires `nowo-tech/form-kit-bundle` ^2.10.
- Requires `nowo-tech/ui-kit-bundle` ^2.15.1.
- **Injected clock (REQ-DI-001)**: services, check runners, the webhook channel and the rollup command read the time
  from `Symfony\Component\Clock\ClockInterface` (the framework `clock` service; `NativeClock` when constructed by
  hand) instead of `new DateTimeImmutable()` / `time()`. `symfony/clock` is now a direct requirement.
  `DueChecksRunner::runDueChecks()` takes `?DateTimeImmutable $now = null` (defaults to the clock).
  `CheckResult` and `Incident` accept an optional timestamp as last constructor argument (default: now).
  Check latency keeps using `hrtime()` (a monotonic duration, not wall-clock time).
- **Network seams for checks**: TCP, SSL and the HTTPS certificate-expiry check open sockets through
  `Check\Network\SocketConnectorInterface` (default `StreamSocketConnector`); `MonitorUrlSsrfGuard` takes optional
  A/AAAA lookup closures; `PingCheckRunner` takes an optional command executor and OS family. Defaults are unchanged.
- **Coverage gate (REQ-TEST-003)**: PHPUnit measures all of `src/` (no exclusions; controllers, Twig extension and
  `Ui` included); `make release-check` and CI run `test-coverage-100`. Test metadata uses `#[CoversClass]`
  attributes. Repository, schema sync, dashboard read-model and command tests run against a real Doctrine ORM schema (`db` group: SQLite in memory,
  MySQL / PostgreSQL with `make test-with-db`); network checks are tested offline (MockHttpClient, fake
  connector/DNS, loopback TLS fixture).

### Deprecated

- `templates.layout` / `templates.layout_template` (a 1.x layout rendering `uptime_content` is wrapped by
  `@NowoUptimeMonitorBundle/_legacy_layout.html.twig`, also for an application override of the former
  `layout.html.twig`), `ui.framework` (only seeds `nowo_ui_kit.css_framework`), `ui.tabler.*`, `ui.bootstrap.*`,
  `ui.tailwind.*` (no effect), `dashboard.enabled`, `dashboard.path`, `dashboard.tenant_paths`, `security.dashboard_roles`,
  `security.manage_roles`, `security.settings_roles`. Removed in 2.0.
- The per-tenant "UI framework" setting no longer changes the markup (pages follow `nowo_ui_kit.css_framework`).

### Removed

- Template contract: `layout.html.twig` (CDN Tabler / Bootstrap / Tailwind layout), `_form_theme.html.twig`,
  `_monitor_actions.html.twig`, `settings/_layout.html.twig`, `settings/_nav.html.twig`, the Bootstrap modal markup of
  `monitor/_monitor_edit_modal.html.twig`, the `uptime_content` / `settings_body` / `title` / `stylesheets` /
  `javascripts` page blocks (now the UiKit canonical and data blocks), and the `uptime-ui-bootstrap.css` /
  `uptime-ui-tailwind.css` assets. See [UPGRADING.md](UPGRADING.md#unreleased).

### Fixed

- `make validate-phpdoc` (REQ-CS-001) missed methods named after a reserved word (`function new()` tokenizes as
  `T_NEW`): any token after `function` is now the method name. `MonitorController::new()` and
  `TenantController::new()` got their PHPDoc.
- Demo: `checks.block_private_urls: false` in the demo config so the local probe monitors (`http://php/demo/uptime/*` over the Compose network) are not blocked by the SSRF guard and report Down.
- **SSL check**: a peer certificate that cannot be parsed now reports "Could not parse certificate" directly (the
  intermediate PEM export step and its unreachable error are gone).

## [1.10.0] - 2026-10-27

### Added

- `dashboard.tenant_paths`: more URL prefixes for the tenant pages, chosen by tenant slug prefix (e.g. personal tenants
  `user-…` under `/uptime/{tenantSlug}` next to project tenants under `/projects/{tenantSlug}/uptime`). Each entry copies
  the tenant routes as `<name_prefix><route>` (`slug_requirement` limits the slug); `Routing\TenantPathUrlGenerator`
  (a `router` decorator) sends links of a matching slug to the copy, so templates keep generating `nowo_uptime_*`, and
  `Routing\TenantPaths::bundleRoute()` names the bundle route a copy stands for.
- `form.hidden_fields`: `MonitorFormType` fields left out of the form (e.g. `project`, `resendNotificationAfterDown`);
  a monitor keeps their stored values.

- `nowo_uptime_monitor.doctrine.table_prefix` (REQ-ORM-002): prepended to every bundle table, index and unique
  constraint by `Doctrine\TablePrefixListener` (`loadClassMetadata`, bundle entities only). Default `''` keeps the
  `uptime_*` names; `nowo:uptime:sync-schema` honours it.
- `notifications.http_timeout` (default 10 s): webhook / Slack delivery timeout and total deadline (`max_duration`).
- `make test-with-db` / `make test-coverage-with-db` run the suite against the MySQL and PostgreSQL services of the root
  `docker-compose.yml` (REQ-TEST-005); the root image ships `pdo_mysql` / `pdo_pgsql`.
- CI: `Demo smoke (HTTP 200)` job on every push / pull request; `demo-smoke.yml` now runs nightly (REQ-TEST-011).
- Docs: timeout hierarchy (CONFIGURATION, DEMO-FRANKENPHP), Messenger retry / failure-transport policy (SCHEDULING),
  logging policy and AI security audit item (SECURITY).

### Changed

- A monitor needs a name: a blank one is refused with `form.monitor.name_required` (`NowoUptimeMonitorBundle` domain)
  instead of being saved without one.
- The tenant slug constraint message is translated from the `NowoUptimeMonitorBundle` domain; the
  `validators.<locale>.yaml` catalogues were removed (REQ-I18N-003).
- `make validate-translations` lints the YAML, rejects non-`NowoUptimeMonitorBundle` catalogues and checks key parity
  across the 7 locales (REQ-MAKE-004); `release-check` runs it. `make help` lists the canonical blocks (REQ-MAKE-001).
- Webhook / Slack failures (transport error or non-2xx status) raise `NotificationDeliveryException` with a redacted
  message (endpoint origin only); `NotificationService` logs sends at `debug` / `info` and failures at `error` without
  raw exception messages (REQ-OBS-001). `RunDueChecksMessageHandler` logs the scan result and rethrows failures for
  the Messenger retry strategy (REQ-MSG-001).
- Linux ping checks add a hard `-w` deadline next to `-W` (REQ-RUNTIME-001).

### Fixed

- Demo: `FRANKENPHP_MODE=classic` no longer fails to boot (`hot_reload` without a Mercure hub), and dashboard live
  updates work in worker mode: both Caddyfiles use FrankenPHP's built-in hub (the separate `mercure` container was
  removed), with explicit PHP / Caddy / `max_wait_time` deadlines. The Flex leftover `compose.override.yaml` that
  published ports `5432` / `80` was removed (REQ-DEMO-006); the `dns:` block carries its explanatory comment
  (REQ-DEMO-009). `make up` rebuilds the (cached) image.

## [1.9.4] - 2026-10-26

### Fixed

- **Host forms re-styled by `uptime-dashboard.css`**: its unscoped `form label`, `form input`, `form select` and
  `form button[type=submit]` rules (1.5rem side margins, 480px inputs) applied to every form of a page that loads the
  stylesheet — host pages and embedded hosts included (inputs pushed out of their rows). They now apply to the
  bundle's own pages only (`.uptime-app` without `.uptime-app--embedded`), with the same specificity.

## [1.9.3] - 2026-10-26

### Fixed

- **Tag colour left empty** answered HTTP 500 (`Expected argument of type "string", "null" given at property path
  "color"`): the colour field submits `''` when empty, like the text fields since 1.9.2.

## [1.9.2] - 2026-10-26

### Fixed

- **Saving a monitor with an optional text field left empty** (project label, description, URL, body, password, …)
  answered HTTP 500 (`Expected argument of type "string", "null" given`): the bundle's text, textarea, URL and password
  fields now submit `''` when empty (`empty_data`). The form types no longer re-import `FormOptionsTrait`, which hid
  the shared field helpers of `AbstractUptimeFormType`.
- **Monitor type options** are the type names (`http`, `https`, `tcp`, …) instead of indexes, so the form's
  per-type field toggle shows the right sections.

## [1.9.1] - 2026-10-26

Hosts that show the bundle inside their own pages. See [UPGRADING](UPGRADING.md#to-191).

### Added

- **Tenant inside the host URLs**: route import `type: nowo_uptime_monitor` (`UptimeMonitorRouteLoader`). When
  `dashboard.path` holds `{tenantSlug}` (e.g. `/projects/{tenantSlug}/uptime`), the tenant pages live under it
  (`/projects/{tenantSlug}/uptime/monitors/new`) and the tenant-less pages (tenant list, new tenant) are left out.
  Without the placeholder the routes are those of `routes.yaml`.

### Fixed

- **Embedded mode**: `.uptime-app--embedded` also follows `data-theme="light|dark"` on an ancestor (UiKit / Tailwind
  hosts), not only `data-bs-theme`, and its rules now win over the framework stylesheet (`uptime-ui-tailwind.css`
  painted the dashboard dark in a light host).
- **Monitor tree**: "No monitors yet." showed its raw key (`_sidebar_tree.html.twig` had no translation domain).

## [1.9.0] - 2026-10-26

Multi-tenant hosts that own their tenants (e.g. one tenant per project of the host). See [UPGRADING](UPGRADING.md#to-190).

### Added

- **Per-tenant access**: `TenantAwareUptimeMonitorAccessCheckerInterface` (`canAccessTenant`,
  `canManageTenantMonitors`, `canManageTenantSettings` with the `{tenantSlug}` of the URL). When the configured access
  checker implements it, the access subscriber decides every request that names a tenant for that tenant; requests
  without one (tenant index, tenant creation) keep the global methods.
- **Status events**: `MonitorWentDownEvent` (monitor, incident, message) when an incident opens and
  `MonitorRecoveredEvent` (monitor, incident) when it resolves, dispatched before the check's flush — a host can open
  and close its own records (issues, tickets) in the same unit of work. The built-in notifications are independent
  (`notifications.enabled`, off by default).
- **`TenantProvisioner`**: `ensure(slug, name)` (create once, keep an existing tenant) and `setEnabled(slug, bool)` for
  hosts that create tenants themselves (with `tenants.list_enabled: false`).

## [1.8.0] - 2026-10-26

Forms in the bundle views are Symfony Form Types rendered with form_start / child loop / form_end, no raw form controls (REQ-TWIG-005). See [UPGRADING](UPGRADING.md#to-180).

### Changed

- Bundle views render every form through Symfony Form Types (REQ-TWIG-005): `settings/history.html.twig` (purge / clear statistics → `CsrfActionFormType`), `settings/tags.html.twig` (per-tag delete → `CsrfActionFormType`), `settings/backup.html.twig` (JSON import → `BackupImportFormType` with `FileType`) and the dashboard sidebar filter (`MonitorSearchFormType`, client-side only). Posted field names and CSRF token ids are unchanged; the settings controller validates the purge, clear, delete-tag and import POSTs through the Form (CSRF listener) instead of `isCsrfTokenValid()`. Template overrides of these views must render the new form variables (see UPGRADING).

### Added

- `make check-no-raw-html-form` (REQ-TWIG-005) fails on raw `<form>` / `<input>` / `<textarea>` / `<select>` in bundle and demo Twig; it runs in `release-check`.

[1.8.0]: https://github.com/nowo-tech/UptimeMonitorBundle/releases/tag/v1.8.0

## [1.7.4] - 2026-10-25

Reproducible JS bundle: the build stamp is a hash of the sources instead of the build time. See [UPGRADING](UPGRADING.md#to-174).

### Changed

- **Assets:** reproducible bundle build. `__UPTIME_ASSETS_BUILD_TIME__` now holds a build id (a hash of the TypeScript/CSS sources and `pnpm-lock.yaml`) instead of the build time, so rebuilding unchanged sources (`make assets`, `make release-check`) no longer rewrites the committed JS; the console line reads `script loaded, build: <id>`.
- Assets: the stale `monitor-form-modal-B1ME1kKz.js` chunk is replaced by the rebuilt one.
- Developer tooling: `make release-check` runs the full REQ-MAKE-002 chain in order (`ensure-up` first, then `check-no-cursor-coauthor`, `check-open-prs`, `check-twig-extra`, `composer-sync`, `cs-fix`, `cs-check`, `rector-dry`, `phpstan`, `igor`, `test-coverage-90`, demo release-check, `test-ts`); `check-open-prs` also fails on conflicted pull requests (REQ-REL-003). `check-no-raw-html-form` (REQ-TWIG-005) is wired once the remaining raw form markup is migrated.

[1.7.4]: https://github.com/nowo-tech/UptimeMonitorBundle/releases/tag/v1.7.4

## [1.7.3] - 2026-10-24

Bundle views render form bodies through the child loop (REQ-TWIG-003). See [UPGRADING](UPGRADING.md#to-173).

### Changed

- Views: `settings/general.html.twig`, `monitor/_monitor_form_fields.html.twig` and `tenant/form.html.twig` render
  their form body through the child loop (`{% for child in form %}` + `not child.rendered` + `form_row(child)`),
  per REQ-TWIG-003. The grouped settings sections and the per-type monitor fieldsets keep their explicit layout
  (rule 3) and a final loop renders any remaining child (e.g. fields added to the FormType or the CSRF token), so
  `general.html.twig` no longer closes the form with `render_rest: false`. Demo: the `otp_demo/show.html.twig`
  template uses the same loop.

[1.7.3]: https://github.com/nowo-tech/UptimeMonitorBundle/releases/tag/v1.7.3

## [1.7.2] - 2026-10-20

Dependency refresh (bundle and demos), php-cs-fixer ParallelConfig(2). See [UPGRADING](UPGRADING.md#to-172).

### Changed

- Dependencies refreshed (Composer and pnpm/npm lock files of the bundle and its demos, campaign 2026-10-19); shipped assets rebuilt.
- `.php-cs-fixer.dist.php`: `new ParallelConfig(2)` (REQ-CS-001).
- Documentation: wave-1 REQ compliance — README Symfony badge label (7.4 | 8.0 | 8.1+), GitHub stars image and documentation list order, canonical issue templates (bug report no longer references another repository), CONTRIBUTING clone URL `UptimeMonitorBundle` and Cursor Agent settings (REQ-IDE-006), tables of contents, SPEC-DRIVEN-DEVELOPMENT Engram relationship, demo README links the FrankenPHP guide, commented demo `.env.example` (MySQL DSN), demo compose project name `uptime-monitor-bundle-demo-symfony-8`, Spec Kit inventory refresh (180/180).

[1.7.2]: https://github.com/nowo-tech/UptimeMonitorBundle/releases/tag/v1.7.2

## [1.7.1] - 2026-10-18

Allows nowo-tech/ui-kit-bundle 2.x (^1.4 || ^2.2) so hosts can move to UiKit 2.2. See [UPGRADING](UPGRADING.md#to-171).

### Changed

- Allows nowo-tech/ui-kit-bundle 2.x (`^1.4 || ^2.2`); the dev lock and the demo lock use 2.2.0. Templates are unchanged (compatibility release).

[1.7.1]: https://github.com/nowo-tech/UptimeMonitorBundle/releases/tag/v1.7.1

## [1.7.0] - 2026-10-14

### Changed

- **Retention purge in keyset batches:** `nowo:uptime:purge-detail`, **Settings → Monitor history → Purge** and the scheduled purge no longer run one unbounded `DELETE` over `uptime_check_result`. Expired rows are removed in batches (`SELECT id … AND id > :afterId ORDER BY id` with the platform limit, then `DELETE … WHERE id IN (…)`), each a short autocommit statement, so large check-detail tables are not locked and the binlog is not flooded. Same rows deleted, same counts and command output.
- `nowo:uptime:clear-data` deletes check results, aggregates and incidents with the same batches.
- Maintenance: applied pending Rector rewrites (null-coalescing assignment, arrow-function return type, non-static local call, early return) in `RollupCommand`, `CheckResultRepository`, `AggregateChartService`, `SummaryPayloadBuilder` and two tests; no behaviour change.

### Added

- `retention.purge_batch_size` (default `1000`, min `1`): rows per batch for the detail purge and clear-data.

[1.7.0]: https://github.com/nowo-tech/UptimeMonitorBundle/releases/tag/v1.7.0

## [1.6.1] - 2026-10-13

### Security

- **SSRF / DNS:** DNS monitors now honour `checks.block_private_urls` (default `true`), like HTTP/TCP/SSL/ping. Private IP literals, `localhost`/`*.localhost` and metadata hostnames are rejected before the lookup, and an answer with a private/local A/AAAA address or a blocked CNAME/MX target is reported **Down** (`… (blocked)`) without storing or showing the resolved records. Previously any user able to create monitors could map internal (split-horizon) resolution and DNS-rebinding names through the dashboard.

[1.6.1]: https://github.com/nowo-tech/UptimeMonitorBundle/releases/tag/v1.6.1

## [1.6.0] - 2026-10-13

### Fixed

- Ping checks report **Down** (`Could not resolve host …`) when DNS returns no addresses and private-URL blocking is on, like TCP/SSL. They no longer fall back to pinging the hostname, which re-resolved it after the SSRF check (DNS rebinding).
- `prepend()` no longer prepends `framework.translator` (`paths`, `fallbacks: [en]`): it overrode the host's fallback order and enabled the translator when the host had not configured it. The bundle catalogue is auto-discovered (same change as UiKit 1.14).

[1.6.0]: https://github.com/nowo-tech/UptimeMonitorBundle/releases/tag/v1.6.0

## [1.5.0] - 2026-10-13

### Fixed

- `prepend()` no longer forces `framework.default_locale: en` and `framework.enabled_locales` on the host (they merged into the application's locale setup). Asset package, translator path and `en` fallback are still prepended.
- Root `enabled` option is now honoured: `false` pauses monitoring (scheduler provides no schedule; `DueChecksRunner` — used by `nowo:uptime:run-due-checks` and `RunDueChecksMessage` — executes nothing). UI and data stay available.

### Deprecated

- Never-read options now trigger a deprecation when set (removal in the next major): `environments`, `table_prefix` (tables are always `uptime_*`), `aggregates.keep_forever` (aggregates are always kept), `multi_tenant.enabled`, `multi_tenant.header` (tenant comes from the URL), `dashboard.prefix` (use `dashboard.path`), `dashboard.roles` (use `security.dashboard_roles`). `docs/CONFIGURATION.md` documents the replacements.

### Changed

- CI: drop the Symfony 7.0 matrix cell (composer requires `^7.4`); the coverage step / job run on Symfony 7.4. Dev constraints `symfony/browser-kit`, `dom-crawler`, `mailer` aligned from `^6.3 || ^7.0 || ^8.0` to `^7.4 || ^8.0`.
- Dev: `vitest` and `@vitest/coverage-v8` `^2.1.9` → `^4.1.11` (pnpm lock regenerated; config unchanged, shipped assets untouched). Vitest 4's AST-based V8 remapping counts differently: the reported TS summary moved from 16.98% to 6.31% (min metric) with the same 9 tests; no TS threshold is configured.

[1.5.0]: https://github.com/nowo-tech/UptimeMonitorBundle/releases/tag/v1.5.0

## [1.4.0] - 2026-10-13

### Security

- **Access control:** creating tenants (`TenantController::new`, GET form and POST) now requires `canManageSettings` (`security.settings_roles`) instead of dashboard access; any other unsafe-method (POST/PUT/PATCH/DELETE) request to an unmapped protected action fails closed to `canManageSettings`.
- **SSRF:** `MonitorUrlSsrfGuard` now blocks `IpUtils::PRIVATE_SUBNETS` plus multicast/broadcast/IETF ranges (adds `0.0.0.0/8`, `100.64.0.0/10`, `fc00::/7`, IPv4-mapped IPv6 such as `::ffff:127.0.0.1`, `::`, `*.localhost`, decimal IPv4 forms) and checks every A/AAAA record.
- **SSRF:** with `checks.block_private_urls: true`, HTTP checks use `NoPrivateNetworkHttpClient` (each redirect hop and the connected IP are re-validated, defeating redirect and DNS-rebinding bypasses) and reject a per-monitor `proxy` on a private network.
- **SSRF:** TCP and SSL checks now honour `checks.block_private_urls`; TCP/SSL/ping connect to the validated, pinned address (SSL keeps SNI/name verification via `peer_name`).
- **Ping:** hosts starting with `-` are rejected (`Invalid ping host`) to prevent option injection into the `ping` binary.

[1.4.0]: https://github.com/nowo-tech/UptimeMonitorBundle/releases/tag/v1.4.0

## [1.3.11] - 2026-10-13

### Changed

- Development: `composer.json` pins `config.platform.php` to 8.2.0 so the committed lock stays installable on the minimum PHP; CI overrides the platform per matrix cell.
- Repository: `.twig-cs-fixer.cache` is now git-ignored.

### Dependencies

- `symfony/mercure` 0.8.0 → 0.9.0 (require-dev constraint + development lock; #97).
- `@types/node` 26.6.4 → 26.6.5 (frontend tooling; #98).
- `nowo-tech/ui-kit-bundle` 1.9.2 → 1.11.0 (development lock; #99).
- `nowo-tech/form-kit-bundle` 2.6.1 → 2.7.1 (development lock; #100).
- `doctrine/orm` 3.7.4 → 3.7.5 (development lock; #101).
- `happy-dom` 20.14.5 → 20.14.6 (frontend tooling; #102).
- `vite` 8.3.2 → 8.3.4 (frontend tooling; #103).

[1.3.11]: https://github.com/nowo-tech/UptimeMonitorBundle/releases/tag/v1.3.11

## [1.3.10] - 2026-10-09

### Changed

- **Doctrine ORM SortDirection:** replace string `'ASC'`/`'DESC'` in `#[ORM\OrderBy]` and QueryBuilder `orderBy`/`addOrderBy` with `SortDirection::Ascending`/`Descending` (doctrine/orm deprecation, https://github.com/doctrine/orm/issues/11313); require `doctrine/orm` `^3.7` where applicable.

### Fixed

- **Assets:** the published bundle now ships `assets/monitor-form-modal-CDzwPBdm.js`, the chunk that `uptime-dashboard.js` / `uptime-monitor-detail.js` import for the monitor form modal (it was missing from the 2026-10-06 rebuild). Unreferenced hashed chunks from older builds were removed.
- **Dashboard:** dropped an always-true Mercure topic `!== null` check (PHPStan 2.3); behaviour unchanged.

### Dependencies

- Dependabot: `doctrine/orm` 3.7.2, `doctrine/dbal` 4.5.0, `nowo-tech/form-kit-bundle` 2.5.3, `nowo-tech/ui-kit-bundle` 1.8.5, `igor-php/igor-php` `^0.10.0` (dev), `friendsofphp/php-cs-fixer` 3.95.27, phpstan group, `nowo-tech/phpstan-frankenphp` 1.2.1; JS dev tooling `vite` 8.3.2, `sass` 1.105.1, `happy-dom` 20.14.5, `@types/node` 26.6.4 (assets rebuilt).
- Composer refresh: `nowo-tech/form-kit-bundle` 2.6.1, `nowo-tech/ui-kit-bundle` 1.9.2, `doctrine/orm` 3.7.4, Symfony 7.4.20 components (lockfile); dev tooling `phpstan/phpstan` 2.3.1, `rector/rector` 2.7.0.
- Demo: Symfony 8.1.8, `doctrine/orm` 3.7.4, `doctrine/dbal` 4.5.0, `twig/twig` 3.30.0; regenerated `config/reference.php`.

[1.3.10]: https://github.com/nowo-tech/UptimeMonitorBundle/releases/tag/v1.3.10

## [1.3.9] - 2026-09-27

### Added

- **REQ-CS-008:** `igor-php/igor-php` (require-dev only), root `igor.json`, Composer/`Makefile` `igor` target, and `release-check` wiring for FrankenPHP worker-state audit.

### Changed

- **Worker safety (Igor):** justified `// @igor-ignore` annotations and/or `ResetInterface` / request-scoped fixes so `make igor` passes on package `src/`.

### Fixed

- **FrankenPHP worker mode (no kernel reset):** the `uptime_theme` / `uptime_ui_framework` Twig globals no longer depend on the current tenant (Twig caches globals for the whole worker). New Twig functions `uptime_theme()` and `uptime_ui_framework()` resolve the tenant values per request (memoized per `Request`, `UptimeUiExtension` implements `ResetInterface`); bundle templates use them.
- **FrankenPHP worker mode:** new `ClosedEntityManagerSubscriber` resets a closed Doctrine manager before bundle routes run, and `MonitorBackupService::import()` resets a closed manager when the flush fails (new optional `ManagerRegistry` argument), so a failed flush no longer breaks later requests of the same worker.
- Fixed PHPStan findings (0 errors at level 8).

[1.3.9]: https://github.com/nowo-tech/UptimeMonitorBundle/releases/tag/v1.3.9

## [1.3.8] - 2026-09-07

### Fixed

- **Schema sync:** treat MySQL `duplicate foreign key` errors as already-applied so idempotent schema sync does not fail when constraints already exist.

### Changed

- **Deps (dev):** phpstan / rector / twig-cs-fixer / php-cs-fixer and frontend tooling bumps.
- **Demos/CI:** stabilize demo-smoke MySQL boot and PDO setup.

### Notes

- **No API or configuration changes** for integrators.

[1.3.8]: https://github.com/nowo-tech/UptimeMonitorBundle/releases/tag/v1.3.8

## [1.3.7] - 2026-08-24

### Changed

- **Demos:** MySQL env policy in FrankenPHP stack (REQ-DEMO-011).
- **QA:** add `phpstan-frankenphp` extension (REQ-CS-005).
- **Docs:** PHP-FIG PSR evaluation (REQ-CS-007).
- **Style:** PHP CS Fixer alignment.

### Notes

- **No API or configuration changes** for integrators unless noted above.

[1.3.7]: https://github.com/nowo-tech/UptimeMonitorBundle/releases/tag/v1.3.7

## [1.3.6] - 2026-08-20

### Tests

- **SSRF regression:** expand `MonitorUrlSsrfGuard` coverage (RFC1918, metadata, IPv6, empty host) and assert HTTP/Ping runners block private targets when `block_private_urls` is enabled (and allow when disabled).

## [1.3.5] - 2026-08-19

### Security

- **Flex recipe:** ship `security_nowo_uptime_monitor.yaml` access rules (REQ-SEC-004). Prefer **`^1.3.5`** over `v1.3.4`.

## [1.3.4] - 2026-08-19

### Security

- **Flex recipe:** `security.access_control` for uptime monitor routes (REQ-SEC-004).

[1.3.4]: https://github.com/nowo-tech/UptimeMonitorBundle/releases/tag/v1.3.4

## [1.3.3] - 2026-08-19

### Security

- **CI:** run `composer audit --locked` after dependency install (REQ-SEC / P3).

[1.3.3]: https://github.com/nowo-tech/UptimeMonitorBundle/releases/tag/v1.3.3

## [1.3.2] - 2026-08-19

### Security

- **Flex recipe `when@prod`:** enforce `checks.block_private_urls: true` to mitigate SSRF in uptime checks.

[1.3.2]: https://github.com/nowo-tech/UptimeMonitorBundle/releases/tag/v1.3.2

## [1.3.1] - 2026-08-18

### Changed

- **Demos:** pin `nowo-tech/hot-reload-bundle` to `^1.4` with FrankenPHP Mercure/`hot_reload` (`dev`/`test` only).
- **Demos:** Symfony 8 only; Symfony 6/7 demo apps removed.

[1.3.1]: https://github.com/nowo-tech/UptimeMonitorBundle/releases/tag/v1.3.1

## [1.3.0] - 2026-08-04

### Changed

- **FormKitBundle:** depend on [`nowo-tech/form-kit-bundle`](https://github.com/nowo-tech/FormKitBundle) ^2.0. Admin form types use `FormOptionsTrait` + profile `uptime_monitor` (`#[FormKitConfig]`). Extension prepends that profile when missing; form types are tagged `form.type` so `FormOptionsMerger` is injected.

### Added
- **REQ-TWIG-004:** require `twig/extra-bundle` + `twig/string-extra`; `make check-twig-extra` in `release-check`; demos register `TwigExtraBundle`.
- **Twig-CS-Fixer:** `vincentlanglet/twig-cs-fixer`, `.twig-cs-fixer.php`, `composer twig:lint` / `twig:fix`.

### Changed

- **REQ-UI-001-kit:** Twig screens import `@NowoUiKitBundle/macros/ui.html.twig` instead of a local `_ui_macros.html.twig` (deleted). Domain monitor open/edit actions live in `_monitor_actions.html.twig`. Requires `nowo-tech/ui-kit-bundle` `^1.4`. Extension seeds `nowo_ui_kit.css_framework` from `ui.framework` when the host has not configured UiKit.

[1.3.0]: https://github.com/nowo-tech/UptimeMonitorBundle/releases/tag/v1.3.0

## [1.2.0] - 2026-08-03

### Added

- **REQ-UI-002:** canonical `security.access_roles` (default `[ROLE_ADMIN]`), `security.access_checker`, `security.allow_unauthenticated` (default `false`), and `AllowAllUptimeMonitorAccessChecker`.
- Compile-time SecurityBundle guard when the dashboard is enabled and `allow_unauthenticated` is `false`.
- `UptimeMonitorAccessSubscriber` enforces `UptimeMonitorAccessCheckerInterface` on admin/manage/API controllers (public status page remains ungated).
- Demos set `allow_unauthenticated: true` (never copy to production).

### Changed

- `ConfigurableUptimeMonitorAccessChecker` applies the general `access_roles` gate before area `dashboard_roles` / `manage_roles` / `settings_roles`.
- Docs: CONFIGURATION / SECURITY / recipe; demo MERCURE.md translated to English.
- Demo `release-verify`: run `sync-schema` + `reset-demo` before HTTP smoke (avoids 500 on empty DB).
- Vitest: add `text-summary` reporter and `coverage-ts` output dir (TS coverage parse + avoid PHP `coverage/` lock).
- Dev deps (Dependabot): php-cs-fixer 3.95.18, rector 2.5.9, phpstan group, vite 8.2.0, `@types/node`, `actions/stale` v11, `phpstan-frankenphp` 1.0.2, symfony/phpunit-bridge.

### Compatibility

- PHP `>=8.2 <8.6`; Symfony `^7.4 || ^8.0`.
- Dashboard with default security settings requires **SecurityBundle** (or set `allow_unauthenticated: true` for trusted local demos).

## [1.1.1] - 2026-07-30

### Fixed

- **Security (REQ-SEC-005):** settings backup import POST now validates CSRF (`backup-import`), matching purge-history / clear-stats.
- **Demo / FrankenPHP** — entrypoint waits for `vendor/autoload_runtime.php` before starting worker mode so `make demo-smoke` / `make up` work on a clean CI checkout (workers no longer exit before Composer).

## [1.1.0] - 2026-07-30

### Added

- **REQ-UI-001 naming aliases (BC):** `templates.layout_template` ≡ `templates.layout`; `ui.css_framework` ≡ `ui.framework`. Twig global alias **`nowo_uptime_layout_template`** (same value as `uptime_layout`).
- Unit coverage for **`TwigPathsPass`** (addPath / prependPath override directory).

### Changed

- Admin pages (`dashboard`, `monitor/form`, `monitor/show`) stack CSS/JS with **`{{ parent() }}`** so host layouts keep their assets when `templates.layout` points at the project (REQ-UI-001).
- Default **`layout.html.twig`**: stylesheets / javascripts live inside blocks so `parent()` stacking works.

### Documentation

- **[USAGE](USAGE.md):** Overriding templates (REQ-TWIG-001) — freeze rule, procedure, correct `NowoUptimeMonitorBundle` override path, layout / CSS stack table.
- **[CONFIGURATION](CONFIGURATION.md):** document layout / `css_framework` aliases and `nowo_uptime_layout_template`.
- **[UPGRADING](UPGRADING.md)** section **Upgrading to 1.1.0**.

## [1.0.11] - 2026-07-29

### Added

- FrankenPHP Friendly banner + canonical README sentence (REQ-DOCS-017).
- Root **`make demo-smoke`** + `.github/workflows/demo-smoke.yml` (REQ-TEST-011).
- `.scripts/check-open-prs.sh` in `release-check` (REQ-REL-003 / MAKE-002).
- Composer keywords `php`, `frankenphp` (REQ-PKG-004).
- README badges: GitHub stars + Coverage (REQ-DOCS-004).
- Ping checks honor `MonitorUrlSsrfGuard` / `block_private_urls` (SSRF harden).

### Changed

- **UI-002**: default `security.dashboard_roles` / `manage_roles` / `settings_roles` and `dashboard.roles` → `ROLE_ADMIN` (demos override to `[]` with firewall off).
- **CS-006**: `ignoreErrors: []`; removed `phpstan-baseline.neon`.
- PHPUnit / CI: `SYMFONY_DEPRECATIONS_HELPER=max[direct]=0` (REQ-SF-005).
- Demo Dockerfiles: FrankenPHP **php8.5** (REQ-DEMO-010).
- README Documentation order; `GITHUB_CI` under Additional (REQ-DOCS-002).
- `validate-translations` paths → `NowoUptimeMonitorBundle.*.yaml`.
- Demo Makefiles: absolute `DOCKER_BIN` (REQ-MAKE-010).
- Demos: `dashboard.sync: polling` by default so localhost smoke avoids Mercure cookie domain errors (Mercure still documented).

### Removed

- Stale PHPStan baseline file.

## [1.0.10] - 2026-07-22

### Changed

- Apply PHP CS Fixer `fully_qualified_strict_types.import_symbols` across `src/` and `tests/` (follow-up to 1.0.9 config change; clears stale-cache gap).
- Demo `symfony7`/`symfony8`: `config/bundles.php` import style aligned with CS Fixer; `composer.lock` synced (`doctrine/dbal` 4.4.4, `twig-inspector-bundle` v1.0.37, path bundle ref).
- Dev: `doctrine/dbal` 4.4.4, `doctrine/doctrine-bundle` 2.18.4 (`composer.lock`).

## [1.0.9] - 2026-07-22

### Added

- Demo `symfony7`/`symfony8`: `FRANKENPHP_MODE` (`classic`|`worker`, default `worker`) via `.env` / Compose and shared `docker/entrypoint.sh` (REQ-DEMO-010). Documented in [`DEMO-FRANKENPHP.md`](DEMO-FRANKENPHP.md).

### Changed

- Demo `symfony7`/`symfony8`: `composer.lock` synced (`nowo-tech/twig-inspector-bundle` v1.0.35).
- Frontend dev toolchain (Dependabot): Vite 8.1.5, happy-dom 20.11.0.
- `.php-cs-fixer.dist.php`: `fully_qualified_strict_types.import_symbols` enabled.

### Fixed

- Demo entrypoints: `git safe.directory` for `/var/uptime-monitor-bundle` (was a leftover `/var/otp-input-bundle` path).

## [1.0.8] - 2026-07-16

### Added

- Contributor Covenant [`CODE_OF_CONDUCT.md`](../CODE_OF_CONDUCT.md); linked from README and [CONTRIBUTING](CONTRIBUTING.md).
- [`docs/GITHUB_CI.md`](GITHUB_CI.md): REQ-GIT-001 (no Cursor co-author trailers) — CI job, hooks, and maintainer scripts.
- `.scripts/check-no-cursor-coauthor.sh`, `.scripts/strip-cursor-coauthor-from-history.sh`, `.githooks/commit-msg`, `.cursor/rules/01-git-commits.mdc`.
- Translation keys for public status page and form cancel/loading across `de`, `fr`, `it`, `nl`, `pt` catalogues (new keys fall back to English where not yet localized).
- Unit test: `UptimeMonitorBundle::build()` registers Twig paths compiler pass.

### Changed

- CI: `git-hygiene` job; Dependabot bumps `actions/checkout` v7, `actions/github-script` v9; `@types/node` 26.1.1.
- Dev: `friendsofphp/php-cs-fixer` 3.95.15, `rector/rector` 2.5.7 (`composer.lock`).
- `make release-check` runs `check-no-cursor-coauthor`; `make setup-hooks` installs `commit-msg`.
- PHPUnit Clover gate: exclude Controllers, Forms, Repositories, Commands, Check runners, Services, Entities, and thin wiring from aggregate coverage (VaultBundle policy); covered by integration/E2E.
- [RELEASE](RELEASE.md): re-run `check-no-cursor-coauthor` after the release commit, before push.
- `.php-cs-fixer.dist.php`: exclude demo `config/reference.php` so CS Fixer does not fight the Symfony generator.

## [1.0.7] - 2026-07-13

### Fixed

- Demo `symfony7`/`symfony8`: `config/reference.php` aligned with Symfony generator output (omit `declare(strict_types=1);`) so PHP CS Fixer CI no longer rewrites auto-generated demo config on every run.

## [1.0.6] - 2026-07-13

### Changed

- `.gitignore`: ignore `.cursor/sandbox.json` (machine-specific Cursor sandbox; REQ-IDE-005).
- Dev: `friendsofphp/php-cs-fixer` 3.95.13, `rector/rector` 2.5.6 (`composer.lock`).
- Demo `symfony7` and `symfony8`: `composer.lock` synced after `make update-deps` (path bundle ref to v1.0.5).
- Demo `symfony7`/`symfony8`: `config/reference.php` — restore `declare(strict_types=1);` (PHP CS Fixer CI).

## [1.0.5] - 2026-07-08

### Changed

- Demo `symfony7` and `symfony8`: `composer.lock` synced after `make update-deps` (path bundle ref, `nowo-tech/twig-inspector-bundle` v1.0.34 in demo dev deps).
- Demo `symfony7`/`symfony8`: regenerated `config/reference.php` (Symfony config schema, including `nowo_uptime_monitor.checks.block_private_urls`).

## [1.0.4] - 2026-07-08

### Added

- GitHub Spec Kit integration: `.specify/`, Cursor Agent skills (`.cursor/skills/speckit-*`), and baseline specs under `specs/001-baseline/` (`spec.md`, `code-inventory.md` covering 100% of `src/`).
- [`docs/SPEC-KIT.md`](SPEC-KIT.md) — install, init, structure, and maintainer workflow for Spec Kit.

### Changed

- [`docs/SPEC-DRIVEN-DEVELOPMENT.md`](SPEC-DRIVEN-DEVELOPMENT.md): three-layer model (Spec Kit baseline, product behavior, `REQ-*` traceability) and contributor workflow.
- README: link to GitHub Spec Kit manual in Documentation.
- Dev: `friendsofphp/php-cs-fixer` 3.95.12 (`composer.lock`).

## [1.0.3] - 2026-07-06

### Changed

- Demo `symfony7` and `symfony8`: `composer.lock` synced after `make update-deps` (follow-up to the Makefile fixes in 1.0.2).

## [1.0.2] - 2026-07-06

### Fixed

- `demo/Makefile`: fixed `Makefile.demo-aggregate-update-deps.mk` include (unterminated `abspath` call); `make update-deps` no longer fails at the demo aggregator.
- Demo `symfony7` and `symfony8` Makefiles: set `COMPOSE` and `SERVICE_PHP` so per-demo `make update-deps` runs reliably.

### Changed

- Frontend dev toolchain (Dependabot): Vite 8.1.3, TypeScript 6.0.3, happy-dom 20.10.6, sass 1.101.0, `@types/node` 26.1.0.
- Dev: PHPUnit 11.5.56, Rector 2.5.4.
- CI: `softprops/action-gh-release` v3.

## [1.0.1] - 2026-07-06

### Fixed

- Unit tests aligned with current service and controller APIs (`MonitorUrlSsrfGuard`, `MonitorRetryService`, `DashboardViewBuilder`, `TenantRepository` in retention services, and related constructors).
- CI Symfony **8.1** matrix: dependency resolution when `doctrine/doctrine-bundle` 2.x conflicted with Symfony 8 components.

### Changed

- Dev dependency: `symfony/mercure` bumped to `^0.7.2` (Symfony 8 compatible).
- CI: pins all bundle Symfony components to the matrix version; uses `doctrine/doctrine-bundle` ^3.2 on Symfony 8.x and ^2.18 on Symfony 7.x.
- CI: Dependabot bumps (`actions/cache` v6, `codecov/codecov-action` v7).

## [1.0.0] - 2026-07-06

First stable release of `nowo-tech/uptime-monitor-bundle`.

### Added

- Synthetic check runners: HTTP/HTTPS, TCP, DNS, SSL certificate, and Ping (ICMP).
- Symfony Scheduler integration and `nowo:uptime:run-due-checks` command.
- Doctrine persistence: tenants, monitors, check results, perpetual aggregates, and incidents.
- YAML retention (`detail_days`) with optional purge; aggregates kept forever (`nowo:uptime:rollup`, `nowo:uptime:purge-detail`).
- Multi-tenant dashboard at `/uptime/{tenantSlug}` with CRUD for monitors and tenants.
- REST summary API (`GET /api/uptime/{tenantSlug}/summary`) with `?since=` delta polling.
- Vite + TypeScript dashboard assets and Chart.js charts.
- Public read-only status page per tenant (`/status/{tenantSlug}`).
- Notifications on status change: email, webhook, and Slack (with cooldown).
- Uptime Kuma–style tenant **Settings** UI (`/uptime/{tenantSlug}/settings`).
- Mercure real-time dashboard sync (`dashboard.sync: mercure`, optional `symfony/mercure-bundle`).
- UI framework options: Tabler (default), Bootstrap, Tailwind, or custom host theme.
- SSRF mitigation for HTTP monitor targets (`MonitorUrlSsrfGuard`).
- Console commands: `nowo:uptime:sync-schema`, `nowo:uptime:seed-demo`, `nowo:uptime:clear-data`.
- Symfony Flex recipe (`.symfony/recipe/nowo-tech/uptime-monitor-bundle/1.0.0/`).
- Translations for UI and validators (`en`, `es`).
- Demo applications under `demo/symfony7` and `demo/symfony8` (FrankenPHP).
- Makefile targets for QA (`release-check`, tests, coverage, PHPStan, Rector) and `update-deps` (REQ-MAKE-008).
- Documentation: Installation, Configuration, Usage, Scheduling, Check types, Monitor configuration, Settings, Notifications, Mercure, Security, Contributing, Release, Engram, and spec-driven development.

### Requirements

- PHP `>=8.2 <8.6`
- Symfony components `^7.4 || ^8.0`
- Doctrine ORM/DBAL (see `composer.json`)

[Unreleased]: https://github.com/nowo-tech/UptimeMonitorBundle/compare/v1.12.1...HEAD
[1.2.0]: https://github.com/nowo-tech/UptimeMonitorBundle/releases/tag/v1.2.0
[1.1.1]: https://github.com/nowo-tech/UptimeMonitorBundle/releases/tag/v1.1.1
[1.1.0]: https://github.com/nowo-tech/UptimeMonitorBundle/releases/tag/v1.1.0
[1.0.11]: https://github.com/nowo-tech/UptimeMonitorBundle/releases/tag/v1.0.11
[1.0.10]: https://github.com/nowo-tech/UptimeMonitorBundle/releases/tag/v1.0.10
[1.0.9]: https://github.com/nowo-tech/UptimeMonitorBundle/releases/tag/v1.0.9
[1.0.8]: https://github.com/nowo-tech/UptimeMonitorBundle/releases/tag/v1.0.8
[1.0.7]: https://github.com/nowo-tech/UptimeMonitorBundle/releases/tag/v1.0.7
[1.0.6]: https://github.com/nowo-tech/UptimeMonitorBundle/releases/tag/v1.0.6
[1.0.5]: https://github.com/nowo-tech/UptimeMonitorBundle/releases/tag/v1.0.5
[1.0.4]: https://github.com/nowo-tech/UptimeMonitorBundle/releases/tag/v1.0.4
[1.0.3]: https://github.com/nowo-tech/UptimeMonitorBundle/releases/tag/v1.0.3
[1.0.2]: https://github.com/nowo-tech/UptimeMonitorBundle/releases/tag/v1.0.2
[1.0.1]: https://github.com/nowo-tech/UptimeMonitorBundle/releases/tag/v1.0.1
[1.0.0]: https://github.com/nowo-tech/UptimeMonitorBundle/releases/tag/v1.0.0
