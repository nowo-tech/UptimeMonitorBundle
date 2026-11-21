# Feature Specification: Report read ports and UI hook events by id

**Feature Branch**: `feat/aggregate-incident-read-ports`  
**Created**: 2026-11-21  
**Status**: Implemented (Unreleased, 1.36.0)  
**Input**: The DDD-host plan (wave D6) left two gaps after 1.35. B7: a host's uptime report still runs DQL and native
SQL over the bundle's `CheckAggregate` and `Incident` entities (totals per monitor over a window, incidents overlapping
it, downtime seconds). B4: three UI hook events still carry entities only (`StatusPageMonitorsEvent`,
`MonitorShowLiveUpdatesEvent`, `UptimePageEvent`).

**Related docs**: [`docs/USAGE.md`](../../docs/USAGE.md#report-reads-136),
[`docs/UPGRADING.md`](../../docs/UPGRADING.md#to-1360), [`docs/CONCURRENCY.md`](../../docs/CONCURRENCY.md),
[`docs/FRANKENPHP-WORKER-AUDIT.md`](../../docs/FRANKENPHP-WORKER-AUDIT.md), previous
[`006-cross-tenant-ports-and-shares/spec.md`](../006-cross-tenant-ports-and-shares/spec.md)

---

## Requirements

- **FR-REPORT-001** `CheckAggregateQueryInterface`: per-monitor totals of the buckets of a half-open window (one query),
  a stable page of buckets (max 1,000) and their count. Read models only.
- **FR-REPORT-002** `IncidentQueryInterface`: a stable page of the incidents overlapping a window, newest first (max
  2,000; open / resolved filter), their count, and per-monitor downtime inside the window summed in SQL over every
  incident (an open incident runs until the caller's "now"). Messages have their URLs masked.
- **FR-REPORT-003** Scope (`MonitorScope`): tenant slugs, single monitors bound to their tenant, an optional id filter.
  An empty scope or window runs no query; invalid slugs and ids are dropped; every row is re-checked against the scope
  in PHP with `===` (house LQ rule: a look-alike tenant that the collation matches is never read).
- **FR-REPORT-004** Concurrency (REQ-RUNTIME-003) and worker mode: plain reads, no lock, never the uncommitted rows of
  a concurrent writer; stateless `readonly` services, nothing managed after a call (kernel reset false).
- **FR-EVENT-001** The three UI hook events carry ids (`getTenantSlug()`, `getMonitorId()` / `getMonitorIds()`,
  `keepMonitorIds()`) and `getOccurredAt()`; the entity accessors keep working, deprecated in the docblocks only.
- **FR-FOLLOWUP-001** Audit XZD follow-ups: `setEnabledMany()` replays lock waits outside a caller's transaction, with
  a two-connection test (I2); doc lines for I1, I4 and I5.

## Acceptance

1. Integration tests on SQLite, MySQL 8.4, MariaDB 11.4 and PostgreSQL 16 cover every requirement, including a writer
   holding row locks on another connection and a look-alike tenant.
2. A kept-alive kernel test (300 requests) reads fresh rows every request with an empty identity map and bound memory.
3. No BC break: new interfaces and classes, optional constructor arguments, defaults unchanged, no schema change.
