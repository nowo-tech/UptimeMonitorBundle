# Feature Specification: Audit trail of monitor changes

**Feature Branch**: `feat/monitor-change-audit`  
**Created**: 2026-11-20  
**Status**: Implemented (Unreleased, 1.33.0)  
**Input**: After GHSA-fv2q-mqc7-6vgh the operators of a host could not check which monitors had their type, proxy or
TLS setting changed since 1.25.1: nothing records monitor edits. They need an audit trail of every monitor change
(who, when, which fields) that never stores a credential.

**Related docs**: [`docs/USAGE.md`](../../docs/USAGE.md#monitor-change-audit-133),
[`docs/CONFIGURATION.md`](../../docs/CONFIGURATION.md), [`docs/SECURITY.md`](../../docs/SECURITY.md#monitor-change-audit-133),
baseline [`001-baseline/spec.md`](../001-baseline/spec.md)

---

## User Scenarios & Testing

### US-01 — Know who changed the route of a monitor (Priority: P1)

As an operator, after enabling `audit.enabled`, every create, change and delete of a monitor dispatches one
`MonitorChangedEvent` after the commit, with the actor, the time, the tenant, the monitor id, the write path and the
field diff.

**Acceptance**:

1. Every write path is covered: new / edit forms, delete, pause / resume, push token issue / revoke, backup import,
   `nowo:uptime:apply-monitors`, the demo seed and host code that writes through the bundle services (FR-AUDIT-001).
2. Plain fields carry before → after: `type`, `target` and `proxy` (origin only), `ignore_tls`, `method`,
   `interval_seconds`, `enabled`, and every other route, schedule or alerting setting (FR-AUDIT-002).
3. Write-only fields carry only `replaced` or `cleared`, never a value or a hash: headers, body, basic-auth username and
   password, URL / proxy userinfo, path and query, push URL key and token, unknown config keys (FR-AUDIT-003).
4. The event is dispatched after the commit, never for a refused or rolled-back write, once for a replayed unit (the
   committed attempt), and after the application's transaction when the bundle runs inside one (FR-AUDIT-004).
5. An update that changes only the check state (next check, streaks, push samples) records nothing.

### US-02 — Read the history on the monitor page (Priority: P2)

With `audit.history.enabled`, the changes are also written to `uptime_monitor_change` in the transaction of the change,
and the monitor page shows the latest ones (read-only, for whoever may view the monitor; markers only for write-only
fields) (FR-AUDIT-005).

### US-03 — Keep the personal data bounded (Priority: P2)

`nowo:uptime:purge-monitor-changes` deletes rows older than `audit.history.retention_days`; `--anonymise-actor=<id>`
replaces an actor identifier in every row (GDPR erasure). Hosts may record a pseudonymous id with
`audit.actor_resolver` (FR-AUDIT-006).

---

## Requirements

- **FR-AUDIT-001**: The bundle's transaction runner opens an audit scope per run; the outermost run's capture records
  the monitor inserts, updates and deletes of the bundle's private manager (never the application's).
- **FR-AUDIT-002**: Target and proxy are recorded through `MonitorSearchUrl::display()` (fails closed: `scheme://***`
  for an ambiguous URL, nothing for an unreadable one).
- **FR-AUDIT-003**: Write-only values exist in memory only for the length of one flush.
- **FR-AUDIT-004**: Events wait in `MonitorChangeOutbox` while the connection is inside a transaction the bundle did not
  open; the commit ledger of nowo-tech/transaction-doctrine decides whether it committed.
- **FR-AUDIT-005**: A failing history insert fails the change (no unaudited write); a failing listener is logged with its
  class only.
- **FR-AUDIT-006**: Everything is off by default (`audit.enabled: false`, `audit.history.enabled: false`).

## Success criteria

- No secret value or hash in any event or history row (tests assert it for every write-only field).
- Tests on SQLite, MySQL 8.4, MariaDB 11.4 and PostgreSQL 16; 100% line coverage; PHPStan clean.
