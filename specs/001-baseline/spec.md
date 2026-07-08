# Feature Specification: UptimeMonitorBundle baseline (100% code coverage)

**Feature Branch**: `001-baseline`  
**Created**: 2026-07-07  
**Status**: Active  
**Input**: Backfill GitHub Spec Kit baseline documenting 100% of production code in `src/`.

**Related docs**: [`docs/SPEC-DRIVEN-DEVELOPMENT.md`](../../docs/SPEC-DRIVEN-DEVELOPMENT.md), [`docs/CONFIGURATION.md`](../../docs/CONFIGURATION.md), [`docs/USAGE.md`](../../docs/USAGE.md)  
**Code inventory (traceability)**: [`code-inventory.md`](code-inventory.md)

---

## Summary

**Package**: `nowo-tech/uptime-monitor-bundle`  
**Configuration root**: `uptime_monitor`


Symfony bundle for **synthetic uptime monitoring** (Uptime Kuma-style): HTTP/TCP/DNS/SSL/Ping/Group checks, multi-tenant dashboard, status pages, retention/aggregates, notifications, and optional Mercure or polling sync.

---

## User Scenarios & Testing

### US-01 — Configure and run scheduled checks (Priority: P1)

As an operator, I define monitors per tenant and the scheduler executes due checks on interval.

**Acceptance**: `RunDueChecksCommand` / `UptimeMonitorScheduleProvider` invoke `DueChecksRunner` → `CheckExecutorService` with typed runners (`HttpCheckRunner`, `TcpCheckRunner`, etc.).

### US-02 — Dashboard with live or polling sync (Priority: P1)

As an operator, I view monitor health in a dashboard that updates via polling or Mercure SSE.

**Acceptance**: `dashboard.sync: mercure` wires `mercure-subscriber.ts`; `polling` uses `poll-controller.ts`; `DashboardSyncDispatcher` publishes updates.

### US-03 — Retention and aggregates (Priority: P1)

As an operator, I retain check detail for N days while keeping rolled-up aggregates indefinitely.

**Acceptance**: `DetailRetentionService`, `AggregateService`, `RollupCommand`, `PurgeDetailCommand`.

### US-04 — Multi-tenant isolation (Priority: P1)

As a platform team, I isolate monitors, settings, and status pages per tenant slug.

**Acceptance**: `Tenant` entity, `TenantController`, `TenantSettingsMapper`, per-tenant dashboard JSON via `TenantDashboardSerializer`.

### US-05 — Alerts on status change (Priority: P2)

As an operator, I receive email/webhook notifications when monitor status transitions.

**Acceptance**: `StatusTransitionService` opens/closes `Incident` records; `NotificationService` dispatches via tagged channels.

### US-06 — Public status page (Priority: P2)

As an end user, I view a tenant-specific public status page without authentication.

**Acceptance**: `StatusPageController`, `SummaryPayloadBuilder`, `status/index.html.twig`.

### US-07 — Settings, tags, backup (Priority: P2)

As an operator, I manage appearance, history, reverse-proxy, tags, and JSON backup/restore.

**Acceptance**: Settings forms/controllers, `MonitorBackupService`, tag CRUD.

---

## Requirements

### Bundle & DI

- **FR-BUNDLE-001**: Register bundle, extension alias `uptime_monitor`, `TwigPathsPass`.
- **FR-CFG-001 / FR-CFG-002**: Configuration tree (tenants, checks, retention, dashboard sync, notifications, UI framework) and extension parameters.
- **FR-DI-001 / FR-DI-002**: Service wiring in YAML; compiler passes for Twig paths.

### Check execution

- **FR-CHECK-001**: `CheckRunnerInterface` contract for all monitor types.
- **FR-CHECK-004**: Type-specific runners (HTTP, TCP, DNS, SSL, Ping, Group) with SSRF guard on URLs.
- **FR-CHECK-002 / FR-CHECK-003**: Orchestrated execution and due-check scheduling (CLI, Messenger, Schedule).
- **FR-CHECK-005**: Status transitions, incident lifecycle, retry policy via `MonitorRetryService`.

### Dashboard & API

- **FR-DASH-001 / FR-DASH-002**: Dashboard view model and sync event dispatch.
- **FR-DASH-003 / FR-DASH-004**: Mercure subscriber and polling controller for live UI updates.
- **FR-API-001 / FR-API-002 / FR-API-003**: JSON APIs for status, history, aggregates; frontend API client.

### Persistence & retention

- **FR-ENTITY-001 / FR-REPO-002**: Doctrine entities and repositories for monitors, results, aggregates, incidents, tags, tenants.
- **FR-AGG-001 / FR-AGG-002**: Rollup aggregates and chart payload builder.
- **FR-RET-001**: Detail retention purge aligned with tenant settings.

### Notifications & security

- **FR-NOTIF-001–004**: Pluggable notification channels (email, webhook) and alert dispatch.
- **FR-SEC-001**: Configurable dashboard access checker.
- **FR-SEC-004**: `MonitorUrlSsrfGuard` blocks private/reserved targets for HTTP checks.

### UI & i18n

- **FR-FORM-001 / FR-FORM-002**: Monitor, tenant, tag, and settings form types with DTO models.
- **FR-VIEW-001–011**: Twig dashboard, monitor, settings, status, and shared partials; SCSS themes (Bootstrap/Tailwind/Kuma).
- **FR-UI-010–012**: Vite TypeScript dashboard modules; built assets under `Resources/public/`.
- **FR-I18N-003 / FR-I18N-004**: Translation helper and seven locale YAML files (+ validator messages).

### CLI

- **FR-CLI-001**: Run due checks on demand.
- **FR-CLI-002 / FR-CLI-003**: Demo seed and schema sync.
- **FR-CLI-004**: Maintenance commands (clear data, purge detail, rollup).

---

## Explicit non-goals

- Advanced status-page white-label branding beyond tenant appearance settings.
- Running checks without configured Doctrine storage.

---

## Success Criteria

- **SC-001**: 100% of production files in `src/` appear in [`code-inventory.md`](code-inventory.md) with requirement IDs (180/180 mapped).
- **SC-002**: Configuration keys in `docs/CONFIGURATION.md` match `Configuration.php`.
- **SC-003**: `composer qa` / `make release-check` pass in CI (PHPUnit, PHPStan, Vitest where applicable).
- **SC-004**: No Packagist-visible behavior change without spec, inventory, and test updates.

---

## Validation

| Check | Command |
| --- | --- |
| Full QA | `make release-check` or `composer qa` |
| Code inventory audit | `find src -type f ! -path '*/assets/dist/*' ! -name '*.test.ts' \| wc -l` |
| TS tests | `pnpm test` or `make test-ts` (when assets present) |

When changing behavior, update this spec, `code-inventory.md`, integrator docs, and tests.
