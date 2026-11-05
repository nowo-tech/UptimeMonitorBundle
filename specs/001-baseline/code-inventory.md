# Code inventory — 100% traceability

**Baseline spec**: [`spec.md`](spec.md)  
**Package**: `nowo-tech/uptime-monitor-bundle`  
**Last audited**: 2026-11-05 (Unreleased, SSL SPKI pins)

This file proves that **every production source artifact** under `src/` is referenced by the baseline specification. Test-only files under `tests/` and `*.test.ts` under `src/` are out of Packagist scope. Built assets under `Resources/public/` are documented as Vite/build outputs.

## Bundle & DI

| Source file | Spec section | Requirement IDs |
| --- | --- | --- |
| `DependencyInjection/Compiler/CsrfProtectionPass.php` | Fail-closed CSRF requirement | FR-DI-002, FR-SEC-005 |
| `DependencyInjection/Compiler/TwigPathsPass.php` | Compiler pass | FR-DI-002 |
| `DependencyInjection/Configuration.php` | Config tree | FR-CFG-001 |
| `DependencyInjection/UptimeMonitorExtension.php` | DI extension | FR-CFG-002 |
| `UptimeMonitorBundle.php` | Bundle entry | FR-BUNDLE-001 |

## CLI

| Source file | Spec section | Requirement IDs |
| --- | --- | --- |
| `Command/ClearDataCommand.php` | CLI maintenance | FR-CLI-004 |
| `Command/PurgeDetailCommand.php` | CLI maintenance | FR-CLI-004 |
| `Command/RollupCommand.php` | CLI maintenance | FR-CLI-004 |
| `Command/RunDueChecksCommand.php` | CLI command | FR-CLI-001 |
| `Command/SeedDemoCommand.php` | CLI demo seed | FR-CLI-002 |
| `Command/SyncSchemaCommand.php` | CLI schema sync | FR-CLI-003 |
| `Service/UptimeDataClearService.php` | Data clear CLI backing | FR-CLI-004 |

## Check runners

| Source file | Spec section | Requirement IDs |
| --- | --- | --- |
| `Check/CheckLimits.php` | Global check caps | FR-CHECK-007 |
| `Check/CheckRunnerInterface.php` | Check runner contract | FR-CHECK-001 |
| `Check/Network/SocketConnection.php` | Open socket of a TCP / SSL probe (read, write, close) | FR-CHECK-006 |
| `Check/Network/SocketConnectorInterface.php` | Socket connector contract (TCP / SSL checks, test seam) | FR-CHECK-006 |
| `Check/Network/StreamSocketConnector.php` | `stream_socket_client` connector with deadline | FR-CHECK-006 |
| `Check/DnsCheckRunner.php` | Dns check runner | FR-CHECK-004 |
| `Check/GroupCheckRunner.php` | Group check runner | FR-CHECK-004 |
| `Check/HttpCheckRunner.php` | Http check runner | FR-CHECK-004 |
| `Check/PingCheckRunner.php` | Ping check runner | FR-CHECK-004 |
| `Check/SslCheckRunner.php` | Ssl check runner (expiry, optional SPKI pinning) | FR-CHECK-004, FR-SSL-PIN-002 |
| `Check/TcpCheckRunner.php` | Tcp check runner | FR-CHECK-004 |
| `Service/CheckExecutorService.php` | Check execution orchestrator | FR-CHECK-002 |
| `Service/DueChecksRunner.php` | Due checks runner | FR-CHECK-003 |
| `Service/StatusTransitionService.php` | Status transition & incidents | FR-CHECK-005 |

## Controllers

| Source file | Spec section | Requirement IDs |
| --- | --- | --- |
| `Controller/AbstractUptimeController.php` | Controller base | FR-CTRL-001 |
| `Controller/DashboardController.php` | Dashboard controller | FR-DASH-001 |
| `Controller/MonitorController.php` | Monitor CRUD controller | FR-MON-001 |
| `Controller/SettingsController.php` | Settings controller | FR-SETTINGS-001 |
| `Controller/StatusPageController.php` | Status page controller | FR-STATUS-001 |
| `Controller/TenantController.php` | Tenant CRUD controller | FR-TENANT-001 |
| `Resources/assets/src/dashboard-panel.ts` | Dashboard panel controller | FR-DASH-001 |
| `Resources/assets/src/poll-controller.ts` | Polling sync controller | FR-DASH-004 |

## REST API

| Source file | Spec section | Requirement IDs |
| --- | --- | --- |
| `Controller/Api/AggregatesApiController.php` | REST API controller | FR-API-001 |
| `Controller/Api/HistoryApiController.php` | REST API controller | FR-API-001 |
| `Controller/Api/StatusApiController.php` | REST API controller | FR-API-001 |

## Persistence

| Source file | Spec section | Requirement IDs |
| --- | --- | --- |
| `Doctrine/KeysetBatchDelete.php` | Keyset batch delete (retention purge, clear-data) | FR-RET-001, FR-RET-002 |
| `Doctrine/TablePrefixListener.php` | `doctrine.table_prefix` metadata listener (REQ-ORM-002) | FR-ENTITY-001, FR-CFG-001 |
| `Entity/CheckAggregate.php` | Persistence model | FR-ENTITY-001 |
| `Entity/CheckResult.php` | Persistence model | FR-ENTITY-001 |
| `Entity/Incident.php` | Persistence model | FR-ENTITY-001 |
| `Entity/Monitor.php` | Persistence model | FR-ENTITY-001 |
| `Entity/Tag.php` | Persistence model | FR-ENTITY-001 |
| `Entity/Tenant.php` | Persistence model | FR-ENTITY-001 |
| `Repository/CheckAggregateRepository.php` | Repository implementation | FR-REPO-002 |
| `Repository/CheckResultRepository.php` | Repository implementation | FR-REPO-002 |
| `Repository/IncidentRepository.php` | Repository implementation | FR-REPO-002 |
| `Repository/MonitorRepository.php` | Repository implementation | FR-REPO-002 |
| `Repository/TagRepository.php` | Repository implementation | FR-REPO-002 |
| `Repository/TenantRepository.php` | Repository implementation | FR-REPO-002 |

## Forms

| Source file | Spec section | Requirement IDs |
| --- | --- | --- |
| `Form/AbstractUptimeFormType.php` | Symfony form type | FR-FORM-001 |
| `Form/BackupImportFormType.php` | Backup import form (multipart file + import mode) | FR-FORM-001 |
| `Form/CsrfActionFormType.php` | CSRF-only action form (purge, clear statistics, delete tag) | FR-FORM-003 |
| `Form/Model/MonitorFormData.php` | Form DTO | FR-FORM-002 |
| `Form/Model/SettingsAppearanceData.php` | Form DTO | FR-FORM-002 |
| `Form/Model/SettingsGeneralData.php` | Form DTO | FR-FORM-002 |
| `Form/Model/SettingsHistoryData.php` | Form DTO | FR-FORM-002 |
| `Form/Model/SettingsReverseProxyData.php` | Form DTO | FR-FORM-002 |
| `Form/Model/TagFormData.php` | Form DTO | FR-FORM-002 |
| `Form/MonitorFormType.php` | Symfony form type | FR-FORM-001 |
| `Form/MonitorSearchFormType.php` | Client-side monitor search field of the dashboard | FR-FORM-001 |
| `Form/SettingsAppearanceFormType.php` | Symfony form type | FR-FORM-001 |
| `Form/SettingsGeneralFormType.php` | Symfony form type | FR-FORM-001 |
| `Form/SettingsHistoryFormType.php` | Symfony form type | FR-FORM-001 |
| `Form/SettingsReverseProxyFormType.php` | Symfony form type | FR-FORM-001 |
| `Form/TagFormType.php` | Symfony form type | FR-FORM-001 |
| `Form/TenantFormType.php` | Symfony form type | FR-FORM-001 |
| `Resources/assets/src/monitor-form-modal.ts` | Monitor modal form | FR-MON-006 |

## Domain models

| Source file | Spec section | Requirement IDs |
| --- | --- | --- |
| `Enum/AggregatePeriod.php` | Domain enum | FR-MDL-001 |
| `Enum/CheckStatus.php` | Domain enum | FR-MDL-001 |
| `Enum/MonitorType.php` | Domain enum | FR-MDL-001 |
| `Model/CheckResultDto.php` | Domain model | FR-MDL-002 |

## Application services

| Source file | Spec section | Requirement IDs |
| --- | --- | --- |
| `Resources/assets/src/api-client.ts` | Dashboard API client | FR-API-001 |
| `Resources/assets/src/chart-theme.ts` | Chart.js theme | FR-UI-011 |
| `Resources/assets/src/dashboard-events-url.ts` | Events URL builder | FR-API-003 |
| `Resources/assets/src/dashboard-ui.ts` | Dashboard UI helpers | FR-DASH-001 |
| `Resources/assets/src/dashboard-update.ts` | Dashboard DOM updater | FR-DASH-002 |
| `Resources/assets/src/history-bar.ts` | Uptime history bar widget | FR-VIEW-011 |
| `Resources/assets/src/uptime-monitor-detail.ts` | Monitor detail page | FR-MON-007 |
| `Service/AggregateChartService.php` | Chart data builder | FR-AGG-002 |
| `Service/AggregateService.php` | Aggregate rollup | FR-AGG-001 |
| `Service/CheckLatencyNormalizer.php` | Latency normalization | FR-METRICS-002 |
| `Service/DashboardSyncDispatcher.php` | Dashboard sync events | FR-DASH-002 |
| `Service/DashboardViewBuilder.php` | Dashboard view model | FR-DASH-001 |
| `Service/DemoSeedService.php` | Demo seed data | FR-CLI-002 |
| `Service/DetailRetentionService.php` | Detail retention purge | FR-RET-001 |
| `Service/MonitorBackupService.php` | Monitor JSON backup | FR-MON-004 |
| `Service/MonitorFactory.php` | Monitor entity factory | FR-MON-002 |
| `Service/NotificationService.php` | Alert dispatch | FR-NOTIF-004 |
| `Service/SummaryPayloadBuilder.php` | Status summary payload | FR-API-002 |
| `Service/TenantDashboardSerializer.php` | Tenant dashboard JSON | FR-TENANT-002 |
| `Service/TenantSettingsMapper.php` | Tenant settings mapping | FR-TENANT-003 |
| `Service/UptimeMetricsService.php` | Prometheus-style metrics | FR-METRICS-001 |
| `Translation/UptimeTranslation.php` | Translation helper | FR-I18N-003 |

## Notifications

| Source file | Spec section | Requirement IDs |
| --- | --- | --- |
| `Notification/Channel/EmailNotificationChannel.php` | Notification channel impl | FR-NOTIF-002 |
| `Notification/Channel/WebhookNotificationChannel.php` | Notification channel impl | FR-NOTIF-002 |
| `Notification/NotificationChannelInterface.php` | Notification channel contract | FR-NOTIF-001 |
| `Notification/NotificationDeliveryException.php` | Redacted delivery failure (REQ-OBS-001) | FR-NOTIF-002 |
| `Notification/UptimeAlert.php` | Notification payload | FR-NOTIF-003 |

## Security

| Source file | Spec section | Requirement IDs |
| --- | --- | --- |
| `Security/AllowAllUptimeMonitorAccessChecker.php` | Permissive checker when `security.allow_unauthenticated` is true (demo/dev only) | FR-SEC-001 |
| `Security/ConfigurableUptimeMonitorAccessChecker.php` | Configurable access checker | FR-SEC-001 |
| `Security/TenantAwareUptimeMonitorAccessCheckerInterface.php` | Per-tenant access decisions for host-owned tenants | FR-SEC-002 |
| `EventSubscriber/UptimeMonitorAccessSubscriber.php` | Enforces the access checker on admin/manage controllers (public status page excluded) | FR-SEC-001 |
| `Security/MonitorUrlSsrfGuard.php` | URL/HTML policy | FR-SEC-004 |
| `Security/SecretRedactor.php` | Credential masking / backup redaction | FR-SEC-006 |
| `Security/UptimeMonitorAccessCheckerInterface.php` | Access checker contract | FR-SEC-001 |
| `Service/MonitorRetryService.php` | Monitor retry policy | FR-MON-003 |

## Twig PHP

| Source file | Spec section | Requirement IDs |
| --- | --- | --- |
| `Twig/UptimeUiExtension.php` | Twig extension | FR-TWIG-001 |

## Persistence integration

| Source file | Spec section | Requirement IDs |
| --- | --- | --- |
| `Service/SchemaSyncService.php` | Schema sync | FR-CLI-003 |
| `EventSubscriber/ClosedEntityManagerSubscriber.php` | Replaces a closed EntityManager before a bundle route runs (workers without kernel reset) | FR-ENTITY-001, FR-WORKER-001 |

## Async messaging

| Source file | Spec section | Requirement IDs |
| --- | --- | --- |
| `Message/RunDueChecksMessage.php` | Async message | FR-MSG-001 |
| `MessageHandler/RunDueChecksMessageHandler.php` | Async message handler | FR-MSG-002 |

## Scheduler

| Source file | Spec section | Requirement IDs |
| --- | --- | --- |
| `Schedule/UptimeMonitorScheduleProvider.php` | Scheduler provider | FR-SCHED-001 |

## Monitor helpers

| Source file | Spec section | Requirement IDs |
| --- | --- | --- |
| `Monitor/HttpHeaderParser.php` | Monitor settings helper | FR-MON-005 |
| `Monitor/MonitorSettings.php` | Monitor settings helper | FR-MON-005 |
| `Monitor/SpkiPins.php` | SSL monitor SPKI pins (parse, validate, hash a certificate) | FR-CHECK-004, FR-SSL-PIN-001 |
| `Monitor/StatusCodeMatcher.php` | Monitor settings helper | FR-MON-005 |
| `Monitor/TenantSettings.php` | Monitor settings helper | FR-MON-005 |

## Events

| Source file | Spec section | Requirement IDs |
| --- | --- | --- |
| `Event/MonitorRecoveredEvent.php` | Dispatched when an incident resolves | FR-EVENT-001 |
| `Event/MonitorsAppliedEvent.php` | Dispatched per tenant after `nowo:uptime:apply-monitors` commits (keys, ids, changed fields; no secrets) | FR-APPLY-005 |
| `Event/MonitorWentDownEvent.php` | Dispatched when an incident opens | FR-EVENT-001 |
| `Event/StatusPageMonitorsEvent.php` | The monitors a public status page shows (host filter) | FR-EVENT-002 |
| `Event/UptimePageEvent.php` | Host header actions and sections of the dashboard and monitor pages | FR-EVENT-002 |

## Routing & tenants

| Source file | Spec section | Requirement IDs |
| --- | --- | --- |
| `Routing/TenantPathUrlGenerator.php` | Router decorator: tenant links to the `web_ui.tenant_paths` copy of a slug | FR-ROUTE-002 |
| `Routing/TenantPaths.php` | Tenant path matching (slug prefix → route copy) | FR-ROUTE-002 |
| `Routing/UptimeMonitorRouteLoader.php` | Route import `type: nowo_uptime_monitor` (prefix with `{tenantSlug}`, tenant path copies) | FR-ROUTE-001 |
| `Service/TenantProvisioner.php` | Host-owned tenants: ensure / enable / disable | FR-TENANT-001 |

## UI page contract

| Source file | Spec section | Requirement IDs |
| --- | --- | --- |
| `Ui/UiFramework.php` | UI framework selector (deprecated: pages follow nowo_ui_kit.css_framework) | FR-UI-003 |
| `Ui/UptimePages.php` | UiKit page contract plumbing: layout, per-page templates, form themes (REQ-UI-003) | FR-UI-003 |
| `Ui/UptimeAreaNav.php` | UiKit area-nav provider (tenant + settings navigation) | FR-UI-004 |
| `Form/SettingsStatusPageFormType.php` | Settings → Status page form (visibility, monitors shown) | FR-UI-006 |
| `Form/Model/SettingsStatusPageData.php` | Settings → Status page form model | FR-UI-006 |
| `Ui/SettingsSections.php` | Settings screens the host keeps (`web_ui.settings.sections`) | FR-UI-006 |
| `Controller/SummaryController.php` | Tenant status summary page (`nowo_uptime_summary`) | FR-UI-007 |
| `Service/StatusSummary.php` | Active monitors, counts per status and overall status of a tenant | FR-UI-007 |
| `Ui/UptimeActions.php` | Page actions by access (dashboard header, New monitor with the tenant limit, monitor edit / pause / delete) | FR-UI-005 |

## Tenant limits

| Source file | Spec section | Requirement IDs |
| --- | --- | --- |
| `Limits/TenantLimitsInterface.php` | Per-tenant limits port (`limits.provider`) | FR-LIMIT-001 |
| `Limits/ConfigurableTenantLimits.php` | Default limits from `limits.max_monitors` / `min_interval_seconds` | FR-LIMIT-001 |
| `Limits/TenantQuota.php` | Monitors a tenant may still add | FR-LIMIT-001 |

## Frontend TypeScript

| Source file | Spec section | Requirement IDs |
| --- | --- | --- |
| `Resources/assets/src/mercure-subscriber.ts` | Mercure SSE subscriber | FR-DASH-003 |
| `Resources/assets/src/uptime-dashboard.ts` | Dashboard entrypoint | FR-UI-010 |
| `Resources/assets/src/uptime-logger.ts` | Frontend logger | FR-UI-012 |
| `Resources/assets/src/vite-env.d.ts` | Vite type shims | FR-BUILD-002 |
| `Resources/public/assets/monitor-form-modal-D2vQZVQS.js` | Built frontend asset (hashed Vite chunk; rename the row when a build changes the hash) | FR-BUILD-001 |
| `Resources/public/auto.css` | Built frontend asset | FR-BUILD-001 |
| `Resources/public/history-bar.css` | Built frontend asset | FR-BUILD-001 |
| `Resources/public/uptime-dashboard.css` | Built frontend asset | FR-BUILD-001 |
| `Resources/public/uptime-dashboard.js` | Built frontend asset | FR-BUILD-001 |
| `Resources/public/uptime-monitor-detail.js` | Built frontend asset | FR-BUILD-001 |
| `Resources/public/uptime-theme.css` | Built frontend asset | FR-BUILD-001 |

## SCSS themes

| Source file | Spec section | Requirement IDs |
| --- | --- | --- |
| `Resources/assets/scss/README.md` | SCSS maintainer notes | FR-STYLE-001 |
| `Resources/assets/scss/_base.scss` | SCSS partial/theme | FR-STYLE-001 |
| `Resources/assets/scss/_forms-settings.scss` | SCSS partial/theme | FR-STYLE-001 |
| `Resources/assets/scss/_forms.scss` | SCSS partial/theme | FR-STYLE-001 |
| `Resources/assets/scss/_host-theme.scss` | SCSS partial/theme | FR-STYLE-001 |
| `Resources/assets/scss/_kuma.scss` | SCSS partial/theme | FR-STYLE-001 |
| `Resources/assets/scss/_mixins.scss` | SCSS partial/theme | FR-STYLE-001 |
| `Resources/assets/scss/_settings.scss` | SCSS partial/theme | FR-STYLE-001 |
| `Resources/assets/scss/_table-view.scss` | SCSS partial (table dashboard, since 1.12) | FR-STYLE-001, FR-UI-005 |
| `Resources/assets/scss/_variables.scss` | SCSS partial/theme | FR-STYLE-001 |
| `Resources/assets/scss/uptime-dashboard.scss` | SCSS partial/theme | FR-STYLE-001 |
| `Resources/assets/scss/_ui-contract.scss` | SCSS partial/theme (UiKit page contract tokens / layout) | FR-STYLE-001 |
| `Resources/assets/scss/uptime-theme.scss` | SCSS partial/theme | FR-STYLE-001 |

## Symfony config

| Source file | Spec section | Requirement IDs |
| --- | --- | --- |
| `Resources/config/routes.yaml` | Service wiring | FR-DI-001 |
| `Resources/config/services.yaml` | Service wiring | FR-DI-001 |

## Translations

| Source file | Spec section | Requirement IDs |
| --- | --- | --- |
| `Resources/translations/NowoUptimeMonitorBundle.de.yaml` | i18n messages | FR-I18N-004 |
| `Resources/translations/NowoUptimeMonitorBundle.en.yaml` | i18n messages | FR-I18N-004 |
| `Resources/translations/NowoUptimeMonitorBundle.es.yaml` | i18n messages | FR-I18N-004 |
| `Resources/translations/NowoUptimeMonitorBundle.fr.yaml` | i18n messages | FR-I18N-004 |
| `Resources/translations/NowoUptimeMonitorBundle.it.yaml` | i18n messages | FR-I18N-004 |
| `Resources/translations/NowoUptimeMonitorBundle.nl.yaml` | i18n messages | FR-I18N-004 |
| `Resources/translations/NowoUptimeMonitorBundle.pt.yaml` | i18n messages | FR-I18N-004 |

## Twig views

| Source file | Spec section | Requirement IDs |
| --- | --- | --- |
| `Resources/views/_history_bar.html.twig` | Shared partial template | FR-VIEW-010 |
| `Resources/views/_legacy_layout.html.twig` | Deprecated bridge around a 1.x layout (uptime_content) | FR-VIEW-001 |
| `Resources/views/_robots.html.twig` | Shared partial template (noindex meta) | FR-VIEW-010 |
| `Resources/views/_stylesheets.html.twig` | Shared partial template | FR-VIEW-010 |
| `Resources/views/dashboard/_events_body.html.twig` | Dashboard template | FR-VIEW-003 |
| `Resources/views/dashboard/_layout_fragment.html.twig` | Layout template | FR-VIEW-001 |
| `Resources/views/dashboard/_quick_stats.html.twig` | Dashboard template | FR-VIEW-003 |
| `Resources/views/dashboard/_sidebar_group_header.html.twig` | Dashboard template | FR-VIEW-003 |
| `Resources/views/dashboard/_sidebar_row.html.twig` | Dashboard template | FR-VIEW-003 |
| `Resources/views/dashboard/_sidebar_tree.html.twig` | Dashboard template | FR-VIEW-003 |
| `Resources/views/dashboard/_table_view.html.twig` | Table dashboard (`web_ui.dashboard.view: table`, since 1.12) | FR-VIEW-003, FR-UI-005 |
| `Resources/views/dashboard/index.html.twig` | Dashboard template | FR-VIEW-003 |
| `Resources/views/monitor/_monitor_edit_modal.html.twig` | Monitor UI template | FR-VIEW-004 |
| `Resources/views/monitor/_monitor_form_body.html.twig` | Monitor UI template | FR-VIEW-004 |
| `Resources/views/monitor/_monitor_form_fields.html.twig` | Monitor UI template | FR-VIEW-004 |
| `Resources/views/monitor/_monitor_form_modal.html.twig` | Monitor UI template | FR-VIEW-004 |
| `Resources/views/monitor/_push_health.html.twig` | Server health view of a push monitor: credentials, gauges, disks, charts ([spec 003](../003-push-monitors/spec.md)) | FR-VIEW-004, FR-PUSH-006 |
| `Resources/views/monitor/form.html.twig` | Monitor UI template | FR-VIEW-004 |
| `Resources/views/monitor/show.html.twig` | Monitor UI template | FR-VIEW-004 |
| `Resources/views/settings/about.html.twig` | Settings UI template | FR-VIEW-002 |
| `Resources/views/settings/appearance.html.twig` | Settings UI template | FR-VIEW-002 |
| `Resources/views/settings/backup.html.twig` | Settings UI template | FR-VIEW-002 |
| `Resources/views/settings/general.html.twig` | Settings UI template | FR-VIEW-002 |
| `Resources/views/settings/history.html.twig` | Settings UI template | FR-VIEW-002 |
| `Resources/views/settings/notifications.html.twig` | Settings UI template | FR-VIEW-002 |
| `Resources/views/settings/section.html.twig` | Settings UI template | FR-VIEW-002 |
| `Resources/views/settings/status_page.html.twig` | Settings → Status page (visibility, monitors shown; since 1.13) | FR-VIEW-002, FR-UI-006 |
| `Resources/views/settings/tags.html.twig` | Settings UI template | FR-VIEW-002 |
| `Resources/views/status/index.html.twig` | Twig template | FR-VIEW-001 |
| `Resources/views/summary/index.html.twig` | Tenant status summary page (since 1.13, [spec 003](../003-push-monitors/spec.md)) | FR-VIEW-001, FR-UI-007 |
| `Resources/views/tenant/form.html.twig` | Twig template | FR-VIEW-001 |
| `Resources/views/tenant/index.html.twig` | Twig template | FR-VIEW-001 |

## Push monitors

Since 1.14 — see [`003-push-monitors`](../003-push-monitors/spec.md).

| Source file | Spec section | Requirement IDs |
| --- | --- | --- |
| `Controller/PushController.php` | Unauthenticated ingest `POST <push.path>/{key}`: bearer token, optional signature, body cap, rate limit, uniform 401 | FR-PUSH-001, FR-PUSH-002, FR-PUSH-004 |
| `Push/PushCredentials.php` | URL key (128 random bits), token issue / revoke / constant-time verify, derived HMAC signing secret | FR-PUSH-002, FR-PUSH-003 |
| `Push/PushPayload.php` | Strict JSON schema of a push (known keys, ranges, at most 32 disks; errors never echo input) | FR-PUSH-004 |
| `Push/PushThresholds.php` | Warning / critical limits per metric and the status of a sample | FR-PUSH-005 |
| `Check/PushCheckRunner.php` | Judges the last push on each interval; no push within interval + grace is down | FR-PUSH-005, FR-CHECK-001 |
| `Push/PushMetricsSeries.php` | Server health view: gauges of the last sample and chart series of the stored checks | FR-PUSH-006 |
| `Resources/assets/src/push-health.ts` | Server health charts (Chart.js, host palette) | FR-PUSH-006, FR-UI-010 |
| `Resources/public/agent/uptime-push-agent.sh` | Reference POSIX agent served with the bundle assets (reads `/proc` and `df`, config parsed never sourced) | FR-PUSH-007 |

## Monitors as code

Since 1.18 — `nowo:uptime:apply-monitors` (see [`docs/PUSH-MONITORS.md`](../../docs/PUSH-MONITORS.md#monitors-as-code)).

| Source file | Spec section | Requirement IDs |
| --- | --- | --- |
| `Command/ApplyMonitorsCommand.php` | Options, stdout JSON, exit codes; no token option | FR-APPLY-001 |
| `Apply/MonitorFileParser.php` | Monitors file schema v1: strict keys, ranges, unique keys and URL keys; errors as JSON pointer + code | FR-APPLY-002 |
| `Apply/MonitorFileSpec.php` | Validated file: entries per tenant slug, file SHA-256 | FR-APPLY-002 |
| `Apply/PushMonitorSpec.php` | One validated push entry with its defaults | FR-APPLY-002 |
| `Apply/PushTokenFileReader.php` | Token intake: 0600 regular file or non-interactive stdin, 64 KiB cap, hashed at once | FR-APPLY-003 |
| `Apply/PushTokenPolicy.php` | Token format (64–128 lowercase hex) and minimum-entropy rules | FR-APPLY-003 |
| `Apply/ExternalPushTokens.php` | Token hashes per entry key; dumps show keys only, not serializable | FR-APPLY-003 |
| `Apply/TokenSourceSystem.php` | System calls behind the token intake (test seam) | FR-APPLY-003 |
| `Apply/NativeTokenSourceSystem.php` | Native lstat / open / fstat / isatty / euid | FR-APPLY-003 |
| `Apply/MonitorApplier.php` | Plan + write under tenant locks in one transaction; create / update / unchanged / prune; UI monitors untouched | FR-APPLY-004 |
| `Apply/ApplyResult.php` | Output lists (no field can hold a secret) | FR-APPLY-001 |
| `Apply/ApplyError.php` | One refusal: path, code, exit class | FR-APPLY-001 |
| `Apply/ApplyRefusedException.php` | Refusal carrying codes only, no previous | FR-APPLY-006 |
| `Apply/ApplyFailedException.php` | Write failure replacing the database exception (class only, no previous) | FR-APPLY-006 |
| `Resources/schema/apply-monitors.v1.json` | JSON Schema of the monitors file, shipped for CI linting | FR-APPLY-002 |

## Coverage summary

| Category | Files | Mapped |
| --- | ---: | ---: |
| Bundle & DI | 5 | 5 |
| CLI | 7 | 7 |
| Check runners | 14 | 14 |
| Controllers | 8 | 8 |
| REST API | 3 | 3 |
| Persistence | 14 | 14 |
| Forms | 18 | 18 |
| Domain models | 4 | 4 |
| Application services | 22 | 22 |
| Notifications | 5 | 5 |
| Security | 8 | 8 |
| Twig PHP | 1 | 1 |
| Persistence integration | 2 | 2 |
| Async messaging | 2 | 2 |
| Scheduler | 1 | 1 |
| Monitor helpers | 5 | 5 |
| Events | 5 | 5 |
| Routing & tenants | 4 | 4 |
| UI page contract | 9 | 9 |
| Tenant limits | 3 | 3 |
| Frontend TypeScript | 11 | 11 |
| SCSS themes | 13 | 13 |
| Symfony config | 2 | 2 |
| Translations | 7 | 7 |
| Twig views | 32 | 32 |
| Push monitors | 8 | 8 |
| Monitors as code | 15 | 15 |
| **Total production sources** | **228** | **228** |

Audit: `find src -type f ! -path '*/assets/dist/*' ! -name '*.test.ts' | wc -l` → **227** (2026-11-04; co-located `*.test.ts` excluded). `make check-spec-inventory` (run by `make release-check`) checks every row, stale rows and this total.
