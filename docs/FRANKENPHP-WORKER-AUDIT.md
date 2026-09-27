# FrankenPHP worker mode audit (kernel not reset between requests)

| Field | Value |
|-------|-------|
| Package | `nowo-tech/uptime-monitor-bundle` (`symfony-bundle`) |
| Audited revision | `v1.3.8` / `07c131b` |
| Audit date | 2026-09-23 |
| Method | Manual review of every PHP file under `src/` (services, controllers, subscriber, Twig extension, form types, check runners, notification channels, repositories, Messenger handler, commands, DI extension, compiler pass, `Resources/config/services.yaml`) |
| **Verdict** | ✅ **Viable under scenario B** — bundle services are stateless or self-invalidating per request; tenant UI values are Twig functions, closed Doctrine managers are recovered. Clearing the Doctrine identity map between requests remains the application's responsibility (W-02) |
| Remediation (2026-09-23) | W-01 resolved (Twig functions `uptime_theme()` / `uptime_ui_framework()`, request-independent globals); W-02 resolved for closed managers (`ClosedEntityManagerSubscriber`, `MonitorBackupService`), identity-map staleness accepted as app responsibility; W-03 / W-04 accepted. Regression tests: `tests/Unit/Twig/UptimeUiExtensionTest.php`, `tests/Unit/EventSubscriber/ClosedEntityManagerSubscriberTest.php`, `tests/Unit/Service/MonitorBackupServiceTest.php` |

## Execution model assumed

FrankenPHP worker mode boots the Symfony kernel once per worker and serves many requests with the same container. This audit assumes the **strict** variant: the kernel is **not** rebooted between requests, so every shared service, static property and PHP global survives from one request to the next. Two scenarios are evaluated:

- **A — kernel not rebooted, `services_resetter` still runs:** services tagged `kernel.reset` (or implementing `ResetInterface`) are reset between requests.
- **B — no reset at all:** nothing is reset; any per-request state kept in a service leaks into the next request.

A bundle that is safe under **B** is safe under **A** and under classic mode / PHP-FPM.

## Summary

| Area | Status | Notes |
|------|--------|-------|
| Mutable state in shared services | ✅ | Only `UptimeUiExtension` keeps a per-`Request` `WeakMap` memo (self-invalidating); other classes are mostly `final readonly` |
| Static properties / `static` locals | ✅ | Only pure static helpers (`StatusCodeMatcher`, `HttpHeaderParser`, `UiFramework::fromString`, `TenantSettings::from`, `MonitorSettings::from`) |
| `ResetInterface` / `kernel.reset` coverage | ✅ | `UptimeUiExtension` implements `ResetInterface`; its memo is also keyed by `Request` (`WeakMap`), so it does not depend on the reset (W-01) |
| Request / user / locale captured in services | ✅ | `RequestStack` and `TokenStorage` are read at call time, never stored |
| Superglobals, `$_ENV`, `putenv`, `ini_set`, `setlocale`, timezone | ✅ | None used; config is compiled into container parameters |
| Doctrine / EntityManager | ✅ / ⚠️ app | Closed managers are reset before bundle routes (W-02); identity-map freshness between requests is the application's responsibility under B |
| Output, headers, `exit`, shutdown functions | ✅ | None; all responses go through `Response` objects |
| Resources (files, sockets, cURL) held open | ✅ | Check runners open sockets per call and `fclose()` them; nothing kept in properties |
| Memory growth across requests | ✅ / ⚠️ app | No bundle-level caches (the Twig memo is a `WeakMap` keyed by `Request`); Doctrine identity map grows under B unless the application clears it (W-02) |
| Blocking I/O and timeouts | ✅ | Checks run outside the HTTP worker; DNS lookups rely on resolver timeouts (W-03, accepted) |
| Third-party static state | ✅ | Only Symfony / Doctrine / Twig; FormKit `FormOptionsTrait` state is safe (see Services) |
| PHPStan FrankenPHP rulesets | ✅ | `ruleset-classic.neon` + `ruleset-worker.neon` included in `phpstan.neon.dist` (lines 14-15) |

A worker demo exists: `demo/symfony8/docker/frankenphp/Caddyfile` declares a `worker` block (line 15).

## Services reviewed

| Service | Shared | Mutable state | Scenario A | Scenario B |
|---------|--------|---------------|------------|------------|
| `Twig\UptimeUiExtension` (globals + functions) | yes | `WeakMap<Request, …>` memo of the tenant theme / framework; globals are static config | ✅ | ✅ W-01 resolved |
| `EventSubscriber\ClosedEntityManagerSubscriber` | yes | none (`readonly`); resets only closed managers on main requests of bundle routes | ✅ | ✅ |
| `EventSubscriber\UptimeMonitorAccessSubscriber` | yes | none (`readonly`); token read per event | ✅ | ✅ |
| `Security\ConfigurableUptimeMonitorAccessChecker` / `AllowAllUptimeMonitorAccessChecker` | yes | none (`readonly` role lists) | ✅ | ✅ |
| Controllers (`Dashboard`, `Monitor`, `Settings`, `Tenant`, `StatusPage`, 3 API controllers) | yes | none (`readonly` deps + config arrays) | ✅ | ✅ (identity map: app, W-02) |
| `Service\DashboardViewBuilder`, `SummaryPayloadBuilder`, `AggregateChartService`, `UptimeMetricsService`, `TenantDashboardSerializer`, `TenantSettingsMapper`, `MonitorFactory`, `MonitorBackupService`, `UptimeDataClearService`, `DetailRetentionService` | yes | none | ✅ | ✅ (identity map: app, W-02) |
| `Service\DashboardSyncDispatcher` (Mercure) | yes | none (`readonly`) | ✅ | ✅ |
| `Service\CheckExecutorService`, `DueChecksRunner`, `StatusTransitionService`, `AggregateService`, `MonitorRetryService`, `CheckLatencyNormalizer`, `NotificationService` | yes | none | ✅ | ✅ (not used by HTTP routes) |
| 6 check runners (`Http`, `Tcp`, `Dns`, `Ssl`, `Ping`, `Group`) + `Security\MonitorUrlSsrfGuard` | yes | none | ✅ | ✅ (not used by HTTP routes) |
| `Notification\Channel\EmailNotificationChannel`, 2 × `WebhookNotificationChannel` | yes | none (`readonly`) | ✅ | ✅ |
| 6 repositories (`ServiceEntityRepository`) | yes | none beyond Doctrine base | ✅ | ✅ (identity map: app, W-02) |
| 7 form types (`AbstractUptimeFormType` + `FormOptionsTrait`) | yes | trait holds the injected merger and a profile name resolved once per class; `withBuilder()` restores the bound builder in `finally` | ✅ | ✅ |
| `Schedule\UptimeMonitorScheduleProvider`, `MessageHandler\RunDueChecksMessageHandler`, 6 commands | yes | none | ✅ | ✅ (CLI / Messenger only) |

Value objects (`CheckResultDto`, `UptimeAlert`, `MonitorSettings`, `TenantSettings`, form models) are created per call and never stored in a service. `SchemaSyncService` is instantiated with `new` inside `SyncSchemaCommand` (`src/Command/SyncSchemaCommand.php:62`) and never runs in HTTP.

## Findings

### W-01 — Tenant-dependent Twig globals are cached by Twig across requests (Medium)

- **Where:** `src/Twig/UptimeUiExtension.php:39-57` (`getGlobals()`), which calls `resolveTheme()` (lines 59-79) and `resolveFramework()` (lines 81-106). Both read `tenantSlug` from the current request and load the tenant to compute `uptime_theme` and `uptime_ui_framework`.
- **Worker impact:** Twig caches globals once the extension set is initialized (`vendor/twig/twig/src/Environment.php:906-917` `resolvedGlobals`, `vendor/twig/twig/src/ExtensionSet.php:350-370`). TwigBundle tags the `twig` service with `kernel.reset` / `resetGlobals` (`vendor/symfony/twig-bundle/DependencyInjection/TwigExtension.php:51-52`), so under **A** the globals are recomputed every request. Under **B** the theme and UI framework of the first tenant rendered by the worker are used for every later request, for all tenants. Controllers override `uptime_theme` in the render context for the dashboard (`src/Controller/DashboardController.php:87`) and settings pages (`src/Controller/SettingsController.php` `baseViewParams()`), but not for monitor, status and tenant pages; `uptime_ui_framework` is never overridden, so the wrong CSS framework / form theme can be loaded. The data involved is appearance configuration, not user data.
- **Recommendation:** keep `services_resetter` enabled. For B-safety, move the tenant-dependent values out of `getGlobals()` into a Twig function (e.g. `uptime_ui_framework()`) or pass them from the controllers, and keep only static config values in globals. This also avoids the two `findOneBySlug()` queries per render.
- **Status:** Resolved — `src/Twig/UptimeUiExtension.php` now returns only request-independent globals (`uptime_ui_framework` = configured `ui.framework`, `uptime_theme` = `auto`) and exposes the Twig functions `uptime_ui_framework()` and `uptime_theme()`, resolved per call and memoized in a `WeakMap` keyed by the current `Request` (one tenant lookup per request); it also implements `ResetInterface`. All bundle templates (`layout.html.twig`, `_stylesheets.html.twig`, `_form_theme.html.twig`, `_monitor_actions.html.twig`, `dashboard/`, `monitor/`, `settings/`, `tenant/`) use the functions. Test `UptimeUiExtensionTest::testConsecutiveRequestsWithoutResetResolveEachTenant` renders the same Twig `Environment` for three tenants without any reset. Global value change documented in `docs/UPGRADING.md`.

### W-02 — Doctrine EntityManager state survives between requests without the Doctrine resetter (Medium)

- **Where:** all HTTP controllers load entities through the default EntityManager, e.g. `MonitorRepository::find()` in `src/Controller/MonitorController.php:205`, `src/Controller/DashboardController.php:155`, `src/Controller/Api/HistoryApiController.php:46`, `src/Controller/Api/AggregatesApiController.php:77`, and flush in `MonitorController` (lines 86, 131, 173, 187) and `SettingsController` (lines 81-207). The backup import catches `Throwable` around a flush (`src/Controller/SettingsController.php:307-313`, flush in `src/Service/MonitorBackupService.php:107`). Bulk DQL deletes in `src/Service/UptimeDataClearService.php` bypass the identity map.
- **Worker impact:** under **A**, DoctrineBundle's `doctrine` registry is reset through `kernel.reset`, which clears the EntityManager and replaces it if it is closed. Under **B**: (1) `find()` returns the already-managed instance without a query, so monitors updated by the scheduler / Messenger consumer (status, `nextCheckAt`, settings) or by another worker stay stale in this worker; (2) the identity map grows with every tenant, monitor and check result loaded (unbounded memory); (3) a failed flush (e.g. constraint violation during import) closes the EntityManager and every later request on that worker fails with "EntityManager is closed".
- **Recommendation:** keep `services_resetter` enabled (default in Symfony runtime for FrankenPHP). If a no-reset setup is unavoidable, add a `kernel.terminate` listener in the host app that calls `ManagerRegistry::resetManager()` when the manager is closed and `clear()` otherwise.
- **Status:** Resolved (closed manager) / Accepted (identity map). (3) New `src/EventSubscriber/ClosedEntityManagerSubscriber.php` (`kernel.request`, priority 31, main requests on `nowo_uptime_*` routes only) calls `ManagerRegistry::resetManager()` for every closed `EntityManagerInterface`; open managers are never touched. `src/Service/MonitorBackupService.php` resets a closed manager when the import flush fails, then rethrows (new optional `?ManagerRegistry` constructor argument, BC). (1) and (2) are **accepted**: the bundle must not `clear()` the application's EntityManager from a listener (it would detach entities the app still holds). Under scenario B, clearing the identity map between requests (e.g. a `kernel.terminate` listener calling `clear()`) remains the application's responsibility; the data involved is dashboard / settings display data, not security decisions.

### W-03 — DNS and ICMP checks rely on system timeouts (Low)

- **Where:** `src/Check/DnsCheckRunner.php:53` (`dns_get_record()`), `src/Security/MonitorUrlSsrfGuard.php:52` (`gethostbyname()`), `src/Check/PingCheckRunner.php:66` (`exec()` of `ping`, bounded by `-W`/`-t` at lines 85-107).
- **Worker impact:** these run only in the check pipeline (`CheckExecutorService`), which is triggered by `RunDueChecksMessageHandler` (Scheduler / Messenger) or `RunDueChecksCommand`; no HTTP route calls it. They do not block HTTP worker threads. HTTP, TCP and SSL checks use explicit timeouts (`src/Check/HttpCheckRunner.php:68-72`, `TcpCheckRunner.php:35-40`, `SslCheckRunner.php:51-58`); the webhook channel uses `timeout: 10` (`src/Notification/Channel/WebhookNotificationChannel.php:56-59`).
- **Recommendation:** run the Messenger consumer as a separate process, never route `RunDueChecksMessage` so that it is handled inside the HTTP worker. Keep the container resolver on short timeouts (`options timeout:1 attempts:2`).
- **Status:** Accepted — not reachable from HTTP routes; PHP offers no per-call timeout for `dns_get_record()` / `gethostbyname()`, so resolver configuration is the correct lever.

### W-04 — Check loop does not clear the EntityManager (Info)

- **Where:** `src/Service/DueChecksRunner.php:21-31` executes every due monitor and `CheckExecutorService::execute()` flushes per monitor (`src/Service/CheckExecutorService.php:61`) without `clear()`.
- **Worker impact:** none for the HTTP worker. In the Messenger consumer the identity map grows during one message; Symfony Messenger's Doctrine clear-EM listener clears it between messages.
- **Recommendation:** keep the default Messenger Doctrine middleware / listeners enabled on the consumer.
- **Status:** Accepted — Messenger / CLI only; no HTTP worker impact.

No other findings. No static state, superglobals, native output functions or long-lived resources were found in `src/`.

## Usage recommendations in worker mode

- Keep `services_resetter` enabled (scenario A) when possible. The bundle is correct under A and B without extra configuration; under B the application should clear the Doctrine identity map between requests.
- In your own templates, use the Twig functions `uptime_theme()` / `uptime_ui_framework()` for tenant-dependent values, not the globals of the same name.
- Run the Scheduler / Messenger consumer (`messenger:consume scheduler_…`) or `nowo:uptime:run-due-checks` in a separate process, not in the FrankenPHP HTTP worker.
- If you override `UptimeUiExtension`, the access checker or any bundle service, keep them stateless or implement `ResetInterface`.
- Do not store `Monitor` / `Tenant` entities in your own shared services between requests.
- If you cannot guarantee resets and do not clear the identity map, set a low `max_requests` on the worker as a mitigation for W-02 staleness.

## Re-audit triggers

Re-run this audit when a change adds: properties or caches to any service, new Twig globals, a new event listener or subscriber, check execution from an HTTP route (e.g. "check now" button), use of `$_SERVER` / `$_ENV` at runtime, or a Doctrine listener that buffers entities.
