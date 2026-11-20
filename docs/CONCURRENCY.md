# Concurrency

How the bundle keeps its writes safe under contention (REQ-RUNTIME-003): many web requests, several scheduler
consumers and long-running workers (FrankenPHP worker mode with the kernel **not** reset, RoadRunner, Messenger) on
MySQL / MariaDB or PostgreSQL. The runner, the lock order, the database settings and the races fixed in 1.29 are in
[HIGH-LOAD.md](HIGH-LOAD.md); this page is the write-path inventory, the conflict answer and the residual risks.

## Table of contents

- [Write paths](#write-paths)
- [Conflict answer](#conflict-answer)
  - [Codes](#codes)
  - [Shapes](#shapes)
  - [Unique violations and lost connections](#unique-violations-and-lost-connections)
- [Host options](#host-options)
- [Standard and DDD hosts, workers](#standard-and-ddd-hosts-workers)
- [Tests](#tests)
- [Residual risks](#residual-risks)

## Write paths

Every write runs on the bundle's runner (`nowo_uptime_monitor.transaction_runner`, `UptimeTransactionRunner`, on the
bundle's private entity manager and nowo-tech/transaction-doctrine): one transaction per unit, side effects (events,
alerts, Mercure, the dashboard sync) after the commit. "Replayed" means `runRetrying()`: up to three attempts with
full-jitter backoff, only as the outermost transaction. Row locks are `SELECT … FOR UPDATE`, always in the order
tenant row → monitor row → that monitor's results, aggregates and incidents.

| Path | Transaction | Control | Conflict answer |
|------|-------------|---------|-----------------|
| Scheduler claim (`DueChecksRunner`, `nowo:uptime:run-due-checks`, `RunDueChecksMessage`) | One statement, outside any unit | Conditional `UPDATE` of `next_check_at` (only if still due and unchanged since the scan): each due monitor is checked by one consumer | None (a lost claim skips the monitor) |
| Check result (`CheckExecutorService`) | One unit per check, replayed | Monitor row lock, monitor read again; aggregate bucket by unique key (`uniq_uptime_aggregate_bucket`) under that lock; network step before the unit | Background: logged, the next scan retries |
| Status transition, incident, alert time (`StatusTransitionService`) | In the check's unit; the alert time in its own unit, replayed | Monitor row lock; alerts after the commit, never sent twice by a replay | Background |
| Push ingest (`PushController`, `nowo_uptime_push`) | One unit, replayed | Monitor row lock; pause and push rate judged again on the locked row (429 for the second push) | 409 + `CONFLICT_001` JSON after the last attempt |
| Monitor create / edit / pause / delete (`MonitorController`) | One unit, one attempt | Tenant row lock with the quota counted again inside (`TenantQuota::withTenantLock()`) for creates and host checks; monitor row lock for edits | 409 + `CONFLICT_001` page (or JSON in the modal) |
| Push token issue / revoke (`MonitorController`) | One unit, one attempt | Random push key, unique (`uniq_uptime_monitor_push_key`); a collision is a real error | 409 + `CONFLICT_001` page |
| Settings screens (`SettingsController`) | One unit, one attempt | Tenant row lock, the screen's own fields applied to the committed settings | 409 + `CONFLICT_001` page |
| Tags (`SettingsController`) | One unit, one attempt | Case-insensitive name check, then the unique key `uniq_uptime_tag_tenant_name`: a concurrent same name is the "name taken" form error | 409 + `CONFLICT_001` page on a lock conflict only |
| Tenant create (`TenantController`) | One unit, one attempt | Slug check, then the unique key `uniq_uptime_tenant_slug`: a taken slug (also by a concurrent request) is the "slug taken" form error | 409 + `CONFLICT_001` page on a lock conflict only |
| Tenant provisioning (`TenantProvisioner::ensure()`) | One unit | Unique slug + catch: the loser reads the winner's row | Not a conflict (both callers get the tenant) |
| Monitors file (`nowo:uptime:apply-monitors`, `MonitorApplier`) | One unit, replayed | Tenant row locks, plan again under the locks; a unique key taken by a concurrent UI write is exit 6 (`EXIT_CONFLICT`, retry) | CLI exit code |
| Backup import (`MonitorBackupService`) | One unit (all rows or none) | Tenant row lock with the quota counted once inside | 409 + `CONFLICT_001` page |
| Rollup (`nowo:uptime:rollup`, `RollupCommand`) | One unit per monitor, replayed; clears only the private manager between monitors | Monitor row lock, that monitor's results read as plain rows | CLI failure after the last attempt |
| Data clear, detail purge (`UptimeDataClearService`, `DetailRetentionService`) | Keyset-batched deletes, then one unit for the monitor reset | Idempotent batches (a rerun finishes the job) | 409 + `CONFLICT_001` page from the settings screen |
| Demo seed (`DemoSeedService`) | One unit per step | Single operator command | CLI failure |
| Monitor change history (1.33, `audit.history.enabled`) | In the unit of the monitor change (same transaction; a failed insert fails the change) | Append-only insert, no lock of its own; `MonitorChangedEvent` after the commit, once for a replayed unit | The change's own answer |
| History purge / anonymisation (`nowo:uptime:purge-monitor-changes`) | Keyset-batched deletes; the anonymisation is one `UPDATE` unit | Idempotent (a rerun finishes the job) | CLI failure |

## Conflict answer

On the bundle's routes (a `nowo_uptime_*` route or a controller of the bundle) `StorageFailureSubscriber`
(`kernel.exception`, priority -64, after your own listeners and before the framework's error listener) answers a
**lock conflict**: a deadlock, a lock wait timeout or a serialization failure, wrapped by the runner as
`Nowo\TransactionContract\TransientStorageFailure` or raw. It uses nowo-tech/transaction-doctrine ^1.5
(`ConflictCode`, `ConcurrencyConflict::$committed`, `ConflictResponsePolicy`, `TransientFailureConflictMapper`).

### Codes

| Code | When | Status | `Retry-After` | Client |
|------|------|--------|---------------|--------|
| `CONFLICT_001` (`ConflictCode::RetrySafe`) | Nothing of the request was committed | 409 (configurable to 503) | Yes: the failure's hint, else `conflict_response.retry_after` | May retry after the delay |
| `CONFLICT_002` (`ConflictCode::Committed`) | Part may already be committed (`ConcurrencyConflict::$committed`, or a lost savepoint with no deadlock behind it) | 409 (configurable to 503) | No | Reload, do not repeat blindly |

### Shapes

The 1.29 keys are kept; `code` is new (additive).

- **Push endpoint and JSON API**: `{"error": "unavailable", "code": "CONFLICT_001"}` or
  `{"error": "conflict", "code": "CONFLICT_002"}`, `Cache-Control: no-store`, `X-Conflict-Code`.
- **HTML pages**: an `HttpException` with the status, `Retry-After` (001 only) and `X-Conflict-Code`, rendered by your
  error page (`templates/bundles/TwigBundle/Exception/error409.html.twig`). The message is generic.

The storage error stays the previous exception, for your logs: no SQL or parameter reaches the client.

### Unique violations and lost connections

They are not conflicts and never get a `CONFLICT_*` code (before 1.31 a unique violation answered 409):

- the expected "only one" races are caught where they happen and answered with the domain result: tenant slug and tag
  name (a form error, 422), tenant provisioning (the existing row), the monitors file (exit 6);
- any other unique violation (a push key collision, a bug) and a lost connection stay real errors (500), for your logs
  and alerts.

## Host options

```yaml
nowo_uptime_monitor:
    conflict_response:
        status: 409        # or 503 (the 1.29 – 1.30 answer); null = the shared policy, else 409
        retry_after: 1     # seconds when the failure has no hint; null = the shared policy, else 1
```

With both options null (the default) and `NowoTransactionDoctrineBundle` registered, the bundle reads the shared
`nowo_transaction_doctrine.conflict_response` policy, so the whole application answers the same. Lock wait limits
(`innodb_lock_wait_timeout`, `lock_timeout`) and DBAL 3 savepoints: [HIGH-LOAD.md](HIGH-LOAD.md#database-settings).

## Standard and DDD hosts, workers

- The bundle writes only through its private entity manager and never flushes, clears or resets an application
  manager; a DDD host that maps the bundle on its own manager gets the same units (REQ-ARCH-001 rule 6).
- Inside a transaction your code opened the bundle's unit is a savepoint and is never replayed: the failure reaches you
  as `TransientStorageFailure` (retry your whole transaction).
- Worker mode with kernel reset false: the runner and the subscriber are stateless; the private manager is replaced
  after a storage failure and dropped at every outermost boundary (`WorkerBoundaryResetListener`).
- Replays reload what they change by id (no entity from an earlier attempt) and send nothing before the commit.

## Tests

- `tests/Unit/EventSubscriber/StorageFailureSubscriberTest.php`: 409 / 503, `CONFLICT_001` with `Retry-After`,
  `CONFLICT_002` without it, serialization failures, unique violations and lost connections left alone.
- `tests/Database/LoadReadinessTest.php` (two connections or two processes on a real server): lock wait, deadlock inside
  a nested flush (both answered 409 / 503 + `CONFLICT_001`), two processes checking one monitor, two schedulers, two
  processes provisioning the same tenants, two connections writing the same tag name (a unique violation, not a
  conflict).
- `tests/Integration/Service/LoadReadinessDbTest.php`: the 1.29 races replayed deterministically.
- CI job `Load readiness` (required): MySQL 8.4, MariaDB 11.4 and PostgreSQL 16, DBAL 4 and DBAL 3.

## Residual risks

- **HTTP writes make one attempt**: a UI write that meets a deadlock answers `CONFLICT_001` instead of being replayed;
  the user retries. Background units are replayed.
- **Data clear and detail purge are batched**: a failure part way leaves some rows deleted (by design, to never lock
  the result tables with one unbounded `DELETE`); running it again finishes the job.
- **Checks outside the unit**: the network step runs before the unit (no lock held during HTTP); a monitor edited
  meanwhile is checked once more with its old configuration, and the result is stored on the edited row.
- **The scheduler's claim needs synchronised clocks** across consumers (NTP).
- **Push token collisions** are not retried (random 128-bit push keys; a collision is a 500).
