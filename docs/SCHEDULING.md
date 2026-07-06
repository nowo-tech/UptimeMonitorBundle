# Scheduling checks

## Recommended: Symfony Scheduler (default)

```yaml
nowo_uptime_monitor:
    scheduler:
        enabled: true
        mode: scheduler
        tick: '1 minute'
```

Run a consumer in production:

```bash
php bin/console messenger:consume scheduler_default -vv
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

Route `ExecuteMonitorCheckMessage` per monitor to an `async` transport for horizontal scaling (phase P5).

## Alternative: dedicated daemon

Custom loop or FrankenPHP worker — highest precision, highest operational cost.
