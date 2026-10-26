# Scheduling checks

## Table of contents

- [Recommended: Symfony Scheduler (default)](#recommended-symfony-scheduler-default)
- [Alternative: system cron](#alternative-system-cron)
- [Alternative: Messenger async](#alternative-messenger-async)
- [Alternative: dedicated daemon](#alternative-dedicated-daemon)
- [Messenger transport, retries and failures](#messenger-transport-retries-and-failures)

## Recommended: Symfony Scheduler (default)

```yaml
nowo_uptime_monitor:
    scheduler:
        enabled: true
        mode: scheduler
        tick: '1 minute'
```

The schedule is named `uptime_monitor` (`#[AsSchedule('uptime_monitor')]`), so its transport is
`scheduler_uptime_monitor`. Run a consumer in production:

```bash
php bin/console messenger:consume scheduler_uptime_monitor --time-limit=3600 --memory-limit=256M -vv
```

| Pros | Cons |
|------|------|
| Native Symfony integration | Requires a long-running worker |
| Dispatches `RunDueChecksMessage` | Needs `symfony/messenger` |

## Alternative: system cron

```cron
* * * * * cd /app && php bin/console nowo:uptime:run-due-checks
```

Set `scheduler.mode: cron` in config (documentation only; you invoke the command yourself).

## Alternative: Messenger async

Set `scheduler.mode: messenger` and dispatch `Nowo\UptimeMonitorBundle\Message\RunDueChecksMessage` yourself (e.g. from
your own schedule), routed to an async transport — see the policy below. There is no per-monitor message: one message
runs the whole due-check scan.

## Alternative: dedicated daemon

Custom loop or FrankenPHP worker — highest precision, highest operational cost.

## Messenger transport, retries and failures

REQ-MSG-001 policy for `RunDueChecksMessage` (handler `RunDueChecksMessageHandler`, `#[AsMessageHandler]`):

| Topic | Behaviour |
|-------|-----------|
| **Message** | `RunDueChecksMessage` — empty, `final`, no payload (nothing to serialize, nothing secret). |
| **Transport (default)** | Scheduler mode: the bundle schedule `uptime_monitor` produces it on the `scheduler_uptime_monitor` transport, consumed by `messenger:consume scheduler_uptime_monitor`. The bundle registers **no** routing: if you dispatch it yourself it is handled **synchronously** unless you route it. |
| **Async routing (optional)** | `framework.messenger.routing: { 'Nowo\UptimeMonitorBundle\Message\RunDueChecksMessage': async }`. Use a single consumer (or a lock) so two scans do not run the same due monitors concurrently. |
| **Retries** | The handler rethrows any failure (database down, …) after logging it, so the transport `retry_strategy` applies (Symfony default: 3 retries, 1 s delay ×2). Scheduler transports do not retry: the next tick (`scheduler.tick`) simply runs a fresh scan. |
| **Failure transport** | Configure `framework.messenger.failure_transport` (e.g. `failed`) for async routing; a message that exhausts its retries lands there. Discarding it is safe — the next tick re-scans every due monitor — so `messenger:failed:retry` is optional. |
| **Idempotency** | Safe on redelivery: only monitors whose `next_check_at` is due run, and each check moves `next_check_at` forward, so a retried message only re-runs monitors that were not checked yet. A retry can at most add one extra sample for a monitor checked just before the failure. |
| **Check failures** | A failing *target* is not a handler failure: the check runner records it as `down` / `unknown` with the error message (no exception, no retry). |
| **Deadlines** | Every check has its own timeout ([CONFIGURATION.md → Timeouts](CONFIGURATION.md#timeouts-frankenphp-safe-req-runtime-001)); recycle long-running consumers with `--time-limit` / `--memory-limit`. |
| **Logging** | `debug` on success (`executed` count), `error` on failure with the message class and the exception **class** only — never payloads, DSNs or targets ([SECURITY.md → Logging policy](SECURITY.md#logging-policy)). |

Example (async routing with a failure transport):

```yaml
framework:
    messenger:
        failure_transport: failed
        transports:
            async:
                dsn: '%env(MESSENGER_TRANSPORT_DSN)%'
                retry_strategy: { max_retries: 3, delay: 1000, multiplier: 2 }
            failed: 'doctrine://default?queue_name=failed'
        routing:
            'Nowo\UptimeMonitorBundle\Message\RunDueChecksMessage': async
```
