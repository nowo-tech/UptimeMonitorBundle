# Mercure real-time sync

When `dashboard.sync` is set to `mercure`, the dashboard receives monitor updates over **Server-Sent Events** instead of HTTP polling.

## Requirements

```bash
composer require symfony/mercure-bundle
```

Configure a Mercure hub in the host application (`config/packages/mercure.yaml`).

## Bundle configuration

```yaml
nowo_uptime_monitor:
    dashboard:
        sync: mercure
        poll_interval_ms: 30000   # fallback if hub unavailable in browser
        mercure:
            topic_template: '/uptime/{tenant}'
            private: true
```

| Option | Description |
|--------|-------------|
| `sync` | `polling` (default) or `mercure` |
| `mercure.topic_template` | Mercure topic per tenant; `{tenant}` = slug |
| `mercure.private` | Private updates (subscriber JWT required) |

## Publishing

After each check, the bundle publishes:

```json
{
  "type": "monitor_update",
  "server_time": "2026-05-20T12:00:00+00:00",
  "monitor": { "id": 1, "name": "...", "last_status": "up", ... }
}
```

Topic example for tenant `main`: `/uptime/main`.

## Demos (Symfony 7 & 8)

Both demos ship with Mercure enabled:

| Demo | App URL | Mercure hub (browser, same origin) |
|------|---------|-----------------------------------|
| symfony7 | http://localhost:8010/uptime/main | http://localhost:8010/.well-known/mercure |
| symfony8 | http://localhost:8011/uptime/main | http://localhost:8011/.well-known/mercure |

Demos proxy `/.well-known/mercure` through FrankenPHP so the **subscriber JWT cookie** is sent (private updates). Publishing still uses `MERCURE_URL=http://mercure/...` inside Docker.

```bash
make -C demo up-symfony8
# Dashboard shows "Sync: mercure"
# Run checks: docker compose exec php php bin/console nowo:uptime:run-due-checks
```

See [DEMO-FRANKENPHP.md](DEMO-FRANKENPHP.md) for Docker services (`mercure` container + env vars).

For the **Symfony 7/8 demos** (proxy Caddy, worker, troubleshooting, console logs): [../demo/MERCURE.md](../demo/MERCURE.md).

## Switching back to polling

```yaml
nowo_uptime_monitor:
    dashboard:
        sync: polling
```

No Mercure hub required; the dashboard uses `GET /api/uptime/{tenant}/summary`.
