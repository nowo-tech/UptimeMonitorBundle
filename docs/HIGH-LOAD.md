# High load

Since 1.29.0 (Beacon spec 239) the bundle is built for many concurrent requests and several scheduler consumers on
long-running workers: FrankenPHP worker mode with the kernel **not** reset between requests, RoadRunner, Messenger
consumers.

## Table of contents

- [One unit of work per write](#one-unit-of-work-per-write)
- [Your entity manager is yours](#your-entity-manager-is-yours)
- [Transient failures: 503, conflicts: 409](#transient-failures-503-conflicts-409)
- [Races that no longer happen](#races-that-no-longer-happen)
- [One lock order](#one-lock-order)
- [Database settings](#database-settings)
- [Workers](#workers)
- [Tests](#tests)

## One unit of work per write

Every bundle write runs on the bundle's own runner, `nowo_uptime_monitor.transaction_runner`
(`Nowo\UptimeMonitorBundle\Transaction\UptimeTransactionRunner`, built on nowo-tech/transaction-doctrine and
implementing nowo-tech/transaction-contract's `TransactionRunnerInterface`):

- one transaction per unit of work (a savepoint inside a transaction your code opened). The bundle's private entity
  manager is flushed inside it and committed by the runner. The bundle never flushes inside a transaction it did not
  open;
- after any failure no transaction of the bundle stays open. The levels it opened are rolled back, and the connection is
  closed only when that rollback fails (after a MySQL / MariaDB deadlock inside a nested flush the server drops the
  savepoints, so `ROLLBACK TO SAVEPOINT` fails with 1305). The worker's next request never inherits a nested
  connection;
- after a storage failure the private manager is closed and replaced; after a refusal only what the unit loaded is
  forgotten;
- side effects follow the commit: incident events, notifications, the dashboard sync, the group refresh, the
  `MonitorsAppliedEvent`.

Units of work that run in the background are replayed after a deadlock or lock wait, up to three attempts with
full-jitter backoff, and only when they are the outermost transaction: scheduled checks, push ingest, the monitors file
(`nowo:uptime:apply-monitors`), the rollup, the time of a sent alert. HTTP writes of the UI make one attempt and answer
503 (the user retries).

## Your entity manager is yours

The bundle reads and writes through its private entity manager (since 1.27.0) and never through a runner your
application binds to `TransactionRunnerInterface` (DoctrineDeadlockRetryBundle's adapter, your own): such a runner
drives your manager. No bundle write flushes, clears, closes or resets an application entity manager
(REQ-ARCH-001 rule 6).

The connection is shared: when your code opens a transaction around a bundle call, the bundle's unit nests in it as a
savepoint, and a bundle failure rolls back to that savepoint only. A transient failure inside your transaction is not
replayed by the bundle (the server may have rolled back your work too): it reaches you as `TransientStorageFailure`;
retry your whole transaction.

## Transient failures: 503, conflicts: 409

On the bundle's routes (a `nowo_uptime_*` route or a controller of the bundle), `StorageFailureSubscriber`
(`kernel.exception`, priority -64) maps:

| Failure | Answer |
|---------|--------|
| `Nowo\TransactionContract\TransientStorageFailure`, or a DBAL `RetryableException` (deadlock, lock wait timeout) | `503 Service Unavailable` with `Retry-After` (the runner's hint, 1 s) |
| A unique key refused a concurrent write (tenant slug, tag name, push URL key) | `409 Conflict` |

The push endpoint and the JSON API answer `{"error": "unavailable"}` / `{"error": "conflict"}` with `Cache-Control:
no-store`; HTML pages get a `ServiceUnavailableHttpException` / `ConflictHttpException`, rendered by your error page.
The storage error stays the previous exception, for your logs: no SQL or parameter reaches the client.

Your own controllers that call the bundle services map `TransientStorageFailure` themselves (or enable
DoctrineDeadlockRetryBundle's HTTP mapping).

## Races that no longer happen

| Before 1.29 | Since 1.29 |
|-------------|------------|
| Every scheduler consumer (or `run-due` command) checked every due monitor | Each due monitor is **claimed** first (`MonitorRepository::claimDue()`: its next check time moves to now + interval only if nobody moved it since the scan), so it is checked once |
| A check wrote back the configuration it loaded before the network step, undoing an edit saved meanwhile | The check's unit locks the monitor row and reads it again, then applies the outcome to that state |
| Two checks of one monitor at once (a group refreshed by two children) lost aggregate counts, failed on the bucket's unique key, opened two incidents or sent a second "down" alert | Checks of one monitor queue on its row; the status, the buckets and the incident are judged on the committed state |
| A deadlock inside the quota write (`TenantQuota::withTenantLock()`, `wrapInTransaction()` with a nested flush) answered a raw `1305` and left the connection nested | One flush at the runner's level; a transient failure answers 503 and leaves the connection at level 0 |
| Two first visits of a project provisioning its tenant: the second failed with a unique violation | The second reads the tenant the first one wrote |
| Two pushes of one monitor at once were both accepted | The pause and the push rate are judged again on the locked row: the second one gets 429 |
| Alerts went out before the check was stored | Alerts follow the commit and are never sent twice by a replay |
| Two settings screens saved at once: the later one wrote back what its page loaded | Each screen locks the tenant row and applies its own fields to the committed settings |
| The rollup overwrote counts recorded by checks while it ran, and loaded every result of every monitor | One monitor per unit, under the monitor row lock, reading that monitor's results as plain rows |

## One lock order

Tenant row (quota writes, the monitors file, settings), then monitor row (checks, push, edit, pause, rollup), then that
monitor's results, aggregates and incidents. The scheduler's claim is a single statement outside any unit. No unit
takes a monitor row and then a tenant row.

The row locks are `SELECT … FOR UPDATE` (none on SQLite, where a writing transaction locks the whole database).

## Database settings

- **DBAL 3:** set `doctrine.dbal.use_savepoints: true` (DBAL 4 always nests with savepoints). Without it a bundle
  failure inside your transaction marks the whole transaction rollback-only.
- **Lock waits:** a unit waits for a locked row as long as the server lets it (`innodb_lock_wait_timeout`, 50 s by
  default on MySQL; `lock_timeout` on PostgreSQL, unlimited by default). Keep it below your request timeout
  (for example 5–10 s for the web workers): a timeout becomes a 503, never a stuck worker.
- **Connection poolers:** PgBouncer in transaction pooling mode is fine (the bundle holds no session state between units);
  statement pooling is not supported.

## Workers

The private manager is replaced on its next call when a failure closed it, and dropped at every outermost request,
message or command boundary (`WorkerBoundaryResetListener`), whatever the route: the closed-manager guard of
`nowo_uptime_*` routes (`ClosedEntityManagerSubscriber`) is only a second line. The runner and the subscriber hold no
state. For connections and managers left by other code, use DoctrineDeadlockRetryBundle's worker guard, or
DoctrineBundle's `doctrine_ping_connection` / `doctrine_clear_entity_manager` Messenger middlewares.

## Tests

- `tests/Integration/Service/LoadReadinessDbTest.php`: the races above replayed deterministically (a second entity
  manager on the same connection plays the other process). SQLite by default, MySQL / PostgreSQL with
  `UPTIME_TEST_DATABASE_URL`.
- `tests/Database` (suite `database`): lock wait, deadlock inside a nested flush, two processes checking one monitor,
  two schedulers, two processes provisioning the same tenants. Skipped without `UPTIME_TEST_DATABASE_URL`.
- CI job `Load readiness`: MySQL 8.4 and PostgreSQL 16, DBAL 4 and DBAL 3, `--fail-on-skipped`.

```bash
UPTIME_TEST_DATABASE_URL='mysql://root@127.0.0.1:3306/uptime?serverVersion=8.4.0' vendor/bin/phpunit --testsuite database
```
