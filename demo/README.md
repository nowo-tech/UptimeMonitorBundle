# Uptime Monitor Bundle — Demos

Two FrankenPHP demos with **Mercure** for real-time dashboard synchronization.

| Demo | Symfony | Dashboard | Hub SSE (browser) |
|------|---------|-----------|------------------------|
| [symfony8](symfony8/) | 8.0 | http://localhost:8011/uptime/main | http://localhost:8011/.well-known/mercure |

Detailed Mercure documentation for the demos: **[MERCURE.md](MERCURE.md)** (architecture, JWT, Caddy proxy, troubleshooting).

FrankenPHP runtime (development and production start, `FRANKENPHP_MODE` switching, troubleshooting): **[docs/DEMO-FRANKENPHP.md](../docs/DEMO-FRANKENPHP.md)**.

## Quick start

```bash
# Symfony 8 + Mercure (recommended)
make -C demo up-symfony8

# Symfony 7 + Mercure
make -C demo up-symfony8
```

The dashboard shows **Mercure · connected** when SSE is open. Each `up` runs **`reset-demo`**: 1 group `[demo]` + 2 HTTP monitors (`demo_uptime_ok`, `demo_uptime_flaky` → routes `/demo/uptime/ok` and `/demo/uptime/flaky/3`).

```bash
# Re-seed from scratch
make -C demo reset-demo-symfony8

# Clear check history only
make -C demo clear-data-symfony8
```

## Mercure (summary)

- Hub built into the demo FrankenPHP server: the **browser** uses it on **8011** (same origin + JWT cookie); it also serves `hot_reload`.
- **`checks-worker`**: publishes to the hub (`MERCURE_URL=http://php/.well-known/mercure`) after each check (`entrypoint: []` in compose).
- **`MERCURE_PUBLIC_URL`**: `http://localhost:8011/.well-known/mercure`.
- Config: `config/packages/nowo_uptime_monitor.yaml` → `sync: mercure`.

See [MERCURE.md](MERCURE.md) for diagram, variables, security, and common errors.

## Demo app configuration

```yaml
# config/packages/nowo_uptime_monitor.yaml
dashboard:
    sync: mercure
    mercure:
        topic_template: '/uptime/{tenant}'
```

```env
# .env — symfony8
MERCURE_URL=http://mercure/.well-known/mercure
MERCURE_PUBLIC_URL=http://localhost:8011/.well-known/mercure
MERCURE_JWT_SECRET=!ChangeThisMercureHubJWTSecretKey!
```

Host application integration: [../docs/MERCURE.md](../docs/MERCURE.md).
