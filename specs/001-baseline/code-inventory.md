# Code inventory — 100% traceability

**Baseline spec**: [`spec.md`](spec.md)  
**Package**: `nowo-tech/uptime-monitor-bundle`  
**Last audited**: 2026-07-07

This file proves that **every production source artifact** under `src/` is referenced by the baseline specification. Test-only files under `tests/` and `*.test.ts` under `src/` are out of Packagist scope. Built assets under `Resources/public/` are documented as Vite/build outputs.

## Bundle & DI

| Source file | Spec section | Requirement IDs |
| --- | --- | --- |
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
| `Check/CheckRunnerInterface.php` | Check runner contract | FR-CHECK-001 |
| `Check/DnsCheckRunner.php` | Dns check runner | FR-CHECK-004 |
| `Check/GroupCheckRunner.php` | Group check runner | FR-CHECK-004 |
| `Check/HttpCheckRunner.php` | Http check runner | FR-CHECK-004 |
| `Check/PingCheckRunner.php` | Ping check runner | FR-CHECK-004 |
| `Check/SslCheckRunner.php` | Ssl check runner | FR-CHECK-004 |
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
| `Form/Model/MonitorFormData.php` | Form DTO | FR-FORM-002 |
| `Form/Model/SettingsAppearanceData.php` | Form DTO | FR-FORM-002 |
| `Form/Model/SettingsGeneralData.php` | Form DTO | FR-FORM-002 |
| `Form/Model/SettingsHistoryData.php` | Form DTO | FR-FORM-002 |
| `Form/Model/SettingsReverseProxyData.php` | Form DTO | FR-FORM-002 |
| `Form/Model/TagFormData.php` | Form DTO | FR-FORM-002 |
| `Form/MonitorFormType.php` | Symfony form type | FR-FORM-001 |
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
| `Ui/UiFramework.php` | UI framework selector | FR-UI-002 |

## Notifications

| Source file | Spec section | Requirement IDs |
| --- | --- | --- |
| `Notification/Channel/EmailNotificationChannel.php` | Notification channel impl | FR-NOTIF-002 |
| `Notification/Channel/WebhookNotificationChannel.php` | Notification channel impl | FR-NOTIF-002 |
| `Notification/NotificationChannelInterface.php` | Notification channel contract | FR-NOTIF-001 |
| `Notification/UptimeAlert.php` | Notification payload | FR-NOTIF-003 |

## Security

| Source file | Spec section | Requirement IDs |
| --- | --- | --- |
| `Security/ConfigurableUptimeMonitorAccessChecker.php` | Configurable access checker | FR-SEC-001 |
| `Security/MonitorUrlSsrfGuard.php` | URL/HTML policy | FR-SEC-004 |
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
| `Monitor/StatusCodeMatcher.php` | Monitor settings helper | FR-MON-005 |
| `Monitor/TenantSettings.php` | Monitor settings helper | FR-MON-005 |

## Frontend TypeScript

| Source file | Spec section | Requirement IDs |
| --- | --- | --- |
| `Resources/assets/src/mercure-subscriber.ts` | Mercure SSE subscriber | FR-DASH-003 |
| `Resources/assets/src/uptime-dashboard.ts` | Dashboard entrypoint | FR-UI-010 |
| `Resources/assets/src/uptime-logger.ts` | Frontend logger | FR-UI-012 |
| `Resources/assets/src/vite-env.d.ts` | Vite type shims | FR-BUILD-002 |
| `Resources/public/assets/auto--seiLA4z.js` | Built frontend asset | FR-BUILD-001 |
| `Resources/public/assets/history-bar-Ce8RTvcF.js` | Built frontend asset | FR-BUILD-001 |
| `Resources/public/assets/history-bar-ClVHg2uN.js` | Built frontend asset | FR-BUILD-001 |
| `Resources/public/assets/monitor-form-modal-B0e9H_EM.js` | Built frontend asset | FR-BUILD-001 |
| `Resources/public/assets/monitor-form-modal-jRo8vHvQ.js` | Built frontend asset | FR-BUILD-001 |
| `Resources/public/auto.css` | Built frontend asset | FR-BUILD-001 |
| `Resources/public/history-bar.css` | Built frontend asset | FR-BUILD-001 |
| `Resources/public/uptime-dashboard.css` | Built frontend asset | FR-BUILD-001 |
| `Resources/public/uptime-dashboard.js` | Built frontend asset | FR-BUILD-001 |
| `Resources/public/uptime-monitor-detail.js` | Built frontend asset | FR-BUILD-001 |
| `Resources/public/uptime-theme.css` | Built frontend asset | FR-BUILD-001 |
| `Resources/public/uptime-ui-bootstrap.css` | Built frontend asset | FR-BUILD-001 |
| `Resources/public/uptime-ui-tailwind.css` | Built frontend asset | FR-BUILD-001 |

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
| `Resources/assets/scss/_variables.scss` | SCSS partial/theme | FR-STYLE-001 |
| `Resources/assets/scss/uptime-dashboard.scss` | SCSS partial/theme | FR-STYLE-001 |
| `Resources/assets/scss/uptime-theme.scss` | SCSS partial/theme | FR-STYLE-001 |
| `Resources/assets/scss/uptime-ui-bootstrap.scss` | SCSS partial/theme | FR-STYLE-001 |
| `Resources/assets/scss/uptime-ui-tailwind.scss` | SCSS partial/theme | FR-STYLE-001 |

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
| `Resources/translations/validators.de.yaml` | i18n messages | FR-I18N-004 |
| `Resources/translations/validators.en.yaml` | i18n messages | FR-I18N-004 |
| `Resources/translations/validators.es.yaml` | i18n messages | FR-I18N-004 |
| `Resources/translations/validators.fr.yaml` | i18n messages | FR-I18N-004 |
| `Resources/translations/validators.it.yaml` | i18n messages | FR-I18N-004 |
| `Resources/translations/validators.nl.yaml` | i18n messages | FR-I18N-004 |
| `Resources/translations/validators.pt.yaml` | i18n messages | FR-I18N-004 |

## Twig views

| Source file | Spec section | Requirement IDs |
| --- | --- | --- |
| `Resources/views/_form_theme.html.twig` | Shared partial template | FR-VIEW-010 |
| `Resources/views/_history_bar.html.twig` | Shared partial template | FR-VIEW-010 |
| `Resources/views/_stylesheets.html.twig` | Shared partial template | FR-VIEW-010 |
| `Resources/views/_ui_macros.html.twig` | Shared partial template | FR-VIEW-010 |
| `Resources/views/dashboard/_events_body.html.twig` | Dashboard template | FR-VIEW-003 |
| `Resources/views/dashboard/_layout_fragment.html.twig` | Layout template | FR-VIEW-001 |
| `Resources/views/dashboard/_quick_stats.html.twig` | Dashboard template | FR-VIEW-003 |
| `Resources/views/dashboard/_sidebar_group_header.html.twig` | Dashboard template | FR-VIEW-003 |
| `Resources/views/dashboard/_sidebar_row.html.twig` | Dashboard template | FR-VIEW-003 |
| `Resources/views/dashboard/_sidebar_tree.html.twig` | Dashboard template | FR-VIEW-003 |
| `Resources/views/dashboard/index.html.twig` | Dashboard template | FR-VIEW-003 |
| `Resources/views/layout.html.twig` | Layout template | FR-VIEW-001 |
| `Resources/views/monitor/_monitor_edit_modal.html.twig` | Monitor UI template | FR-VIEW-004 |
| `Resources/views/monitor/_monitor_form_body.html.twig` | Monitor UI template | FR-VIEW-004 |
| `Resources/views/monitor/_monitor_form_fields.html.twig` | Monitor UI template | FR-VIEW-004 |
| `Resources/views/monitor/_monitor_form_modal.html.twig` | Monitor UI template | FR-VIEW-004 |
| `Resources/views/monitor/form.html.twig` | Monitor UI template | FR-VIEW-004 |
| `Resources/views/monitor/show.html.twig` | Monitor UI template | FR-VIEW-004 |
| `Resources/views/settings/_layout.html.twig` | Layout template | FR-VIEW-001 |
| `Resources/views/settings/_nav.html.twig` | Settings UI template | FR-VIEW-002 |
| `Resources/views/settings/about.html.twig` | Settings UI template | FR-VIEW-002 |
| `Resources/views/settings/appearance.html.twig` | Settings UI template | FR-VIEW-002 |
| `Resources/views/settings/backup.html.twig` | Settings UI template | FR-VIEW-002 |
| `Resources/views/settings/general.html.twig` | Settings UI template | FR-VIEW-002 |
| `Resources/views/settings/history.html.twig` | Settings UI template | FR-VIEW-002 |
| `Resources/views/settings/notifications.html.twig` | Settings UI template | FR-VIEW-002 |
| `Resources/views/settings/section.html.twig` | Settings UI template | FR-VIEW-002 |
| `Resources/views/settings/tags.html.twig` | Settings UI template | FR-VIEW-002 |
| `Resources/views/status/index.html.twig` | Twig template | FR-VIEW-001 |
| `Resources/views/tenant/form.html.twig` | Twig template | FR-VIEW-001 |
| `Resources/views/tenant/index.html.twig` | Twig template | FR-VIEW-001 |

## Coverage summary

| Category | Files | Mapped |
| --- | ---: | ---: |
| Bundle & DI | 4 | 4 |
| CLI | 7 | 7 |
| Check runners | 10 | 10 |
| Controllers | 8 | 8 |
| REST API | 3 | 3 |
| Persistence | 12 | 12 |
| Forms | 15 | 15 |
| Domain models | 4 | 4 |
| Application services | 23 | 23 |
| Notifications | 4 | 4 |
| Security | 4 | 4 |
| Twig PHP | 1 | 1 |
| Persistence integration | 1 | 1 |
| Async messaging | 2 | 2 |
| Scheduler | 1 | 1 |
| Monitor helpers | 4 | 4 |
| Frontend TypeScript | 17 | 17 |
| SCSS themes | 13 | 13 |
| Symfony config | 2 | 2 |
| Translations | 14 | 14 |
| Twig views | 31 | 31 |
| **Total production sources** | **180** | **180** |

Audit: `find src -type f ! -path '*/assets/dist/*' ! -name '*.test.ts' | wc -l`
