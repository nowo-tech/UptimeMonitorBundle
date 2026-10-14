# Feature Specification: Keyset batch retention purge

**Feature Branch**: `002-keyset-batch-purge`  
**Created**: 2026-10-14  
**Status**: Implemented (v1.7.0)  
**Input**: The detail retention purge (`DetailRetentionService`) deleted every expired `CheckResult` row in one unbounded `DELETE … WHERE checked_at < :cutoff`. On large check-detail tables that statement locks the table for its whole duration, builds a huge undo log and fills the binlog / replication stream. `nowo:uptime:clear-data` had the same pattern for check results, aggregates and incidents.

**Related docs**: [`docs/CONFIGURATION.md`](../../docs/CONFIGURATION.md), [`docs/SETTINGS.md`](../../docs/SETTINGS.md), baseline [`001-baseline/spec.md`](../001-baseline/spec.md) (FR-RET-001, FR-CLI-004)

---

## User Scenarios & Testing

### US-01 — Purge a large detail table without locking it (Priority: P1)

As an operator with millions of `uptime_check_result` rows, I run `nowo:uptime:purge-detail` (or **Settings → Monitor history → Purge**) and the database keeps serving check inserts and dashboard reads while old rows are removed.

**Acceptance**:

1. Expired rows are removed in batches of at most `retention.purge_batch_size` rows (default `1000`).
2. Each batch is two short statements: `SELECT id … WHERE <filter> AND id > :afterId ORDER BY id` with the platform limit, then `DELETE … WHERE id IN (:ids)`.
3. The loop stops after the first short batch (fewer ids than the batch size) or an empty select.
4. Rows newer than the cutoff, and rows of other tenants, are kept.
5. The returned count, the command output (`Purged N detail record(s).`) and the settings flash message are unchanged.

### US-02 — Clear data in batches (Priority: P2)

As an operator, `nowo:uptime:clear-data [--tenant=…]` removes check results, aggregates and incidents with the same keyset batches; the returned counts and output are unchanged.

---

## Requirements

- **FR-RET-002**: `Doctrine\KeysetBatchDelete::run(EntityManagerInterface, entityClass, ?filter, batchSize)` (internal) deletes the rows matching a DQL filter in keyset batches (`setMaxResults()` → `AbstractPlatform::modifyLimitQuery()`), outside the unit of work, each statement in autocommit unless the caller opened a transaction. Batch size below 1 is clamped to 1.
- **FR-CFG-003**: `retention.purge_batch_size` (integer, `min: 1`, default `1000`) configures the batch size for the detail purge and for clear-data. Services read it with `KeysetBatchDelete::batchSizeFrom()`, which falls back to `1000` when missing or invalid.
- `DetailRetentionService::purgeExpiredDetailForTenant()` and `UptimeDataClearService::clear()` use FR-RET-002. `UptimeDataClearService` gains an optional `$retentionConfig` constructor argument (default `[]`), so existing direct instantiations keep working.

## Explicit non-goals

- No dependency on `nowo-tech/audit-kit-bundle` (its DBAL `KeysetDelete` helper is equivalent but the bundle does not depend on AuditKit).
- No change to which rows are deleted, nor to retention semantics (`detail_days`, tenant overrides, `purge_enabled`).

## Success Criteria

- **SC-001**: SQLite tests cover more rows than one batch (2500 rows with the default size), an empty table, an exact multiple of the batch size, rows kept by the cutoff / tenant filter, and the statement shape (one `LIMIT`ed select and one `IN (…)` delete per batch).
- **SC-002**: `make release-check` green; `src/Doctrine/KeysetBatchDelete.php` at 100% line coverage.

## Validation

| Check | Command |
| --- | --- |
| Tests | `make test` (see `tests/Unit/Doctrine/KeysetBatchDeleteTest.php`, `tests/Unit/Service/DetailRetentionServiceTest.php`, `tests/Unit/Service/UptimeDataClearServiceTest.php`) |
| Full QA | `make release-check` |
