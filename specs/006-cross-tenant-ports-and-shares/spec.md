# Feature Specification: Cross-tenant ports and monitor shares

**Feature Branch**: `feat/cross-tenant-ports-and-shares`  
**Created**: 2026-11-21  
**Status**: Implemented (1.35.0)  
**Input**: A multi-tenant host moving its monitoring code to a layered (DDD) structure still writes DQL over the
bundle's `Monitor` and `Tenant` entities for its dashboard list, plan usage and summary card. It flips many tenants
with a bulk `UPDATE` and stores monitor shares in a table of its own. It needs ports that return plain values, and a
share store the bundle owns.

**Related docs**: [`docs/USAGE.md`](../../docs/USAGE.md#cross-tenant-reads-and-ports-135),
[`docs/CONFIGURATION.md`](../../docs/CONFIGURATION.md), [`docs/UPGRADING.md`](../../docs/UPGRADING.md#unreleased),
[`docs/CONCURRENCY.md`](../../docs/CONCURRENCY.md), baseline [`001-baseline/spec.md`](../001-baseline/spec.md)

---

## Requirements

- **FR-PORT-001** `MonitorQueryInterface`: list (sorted, paged), count, plan usage (non-paused, groups left out, push
  split, one query), down count, find in tenant. Read models only. The scope is the caller's slug list (whole tenants,
  or single monitors bound to their tenant). An empty scope runs no query, invalid slugs are dropped, and rows are
  re-checked against the scope.
- **FR-PORT-002** `BulkTenantProvisionerInterface::setEnabledMany()`: one idempotent statement; a new interface, so host
  implementations of `TenantProvisionerInterface` keep compiling.
- **FR-PORT-003** `MonitorIdAwareUptimeMonitorAccessCheckerInterface::canViewMonitorId()`: a new interface, implemented
  by the built-in checkers; preferred over `canViewMonitor()` when a monitor-aware checker has it.
- **FR-PORT-004** `MonitorRepositoryInterface::findInTenant()` (exact slug); `MonitorBackupService::export()` takes a
  slug; `MonitorQuotaCheckEvent` carries `monitorId` and `paused`, and its entity accessor is deprecated (removed in
  2.0).
- **FR-PORT-005** Chart-ready series formatter of the history payloads (PHP and Twig).
- **FR-SHARE-001** `MonitorSharesInterface`: create, delete, users of a monitor, monitors shared with a user, user
  erasure. Tenant-safe: a monitor is named with its tenant, which is compared exactly.
- **FR-SHARE-002** Host policy port, deny by default.
- **FR-SHARE-003** Concurrency (REQ-RUNTIME-003): create is one unit; the unique key decides a concurrent double
  submit and the loser returns the winner's share; a lock conflict is a `TransientStorageFailure` (`CONFLICT_001`, or
  `CONFLICT_002` through commit tracking); the host's entity manager is never touched.
- **FR-SHARE-004** Opt-in table `uptime_monitor_share` (`shares.enabled`): mapped only when enabled, binary NO PAD user
  identifier column on MySQL / MariaDB, attribute and XML mapping, table prefix honoured.

## Acceptance

1. Integration tests on SQLite, MySQL 8.4, MariaDB 11.4 and PostgreSQL 16 cover every requirement, including
   two-connection races, a lock wait timeout and the identifier collation.
2. No host entity enters a bundle manager (share writes use a reference by id); nothing stays managed after a call
   (FrankenPHP worker mode, kernel reset off).
3. Without `shares.enabled` the schema of a host is unchanged.
