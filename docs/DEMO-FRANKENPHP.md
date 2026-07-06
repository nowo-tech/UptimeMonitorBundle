# Demo applications with FrankenPHP

The bundle ships **Symfony 7** and **Symfony 8** demos under `demo/symfony7/` and `demo/symfony8/` (not included in the Composer package archive).

## Overview

- **FrankenPHP** (Caddy + PHP) in Docker
- **PostgreSQL** on the internal Docker network (no public DB port required for app use)
- Bundle mounted from the repository root for live code changes
- Default URL: **http://localhost:8011** (see `PORT` in `demo/symfony8/.env.example`)

## Quick start

From the bundle root:

```bash
make -C demo up-symfony7   # http://localhost:8010
make -C demo up-symfony8   # http://localhost:8011
```

This typically:

1. Starts containers
2. Runs `composer install` / update
3. Runs `nowo:uptime:sync-schema` and `nowo:uptime:seed-demo`
4. Runs an initial `nowo:uptime:run-due-checks`

## URLs

| Path | Description |
|------|-------------|
| `/uptime/main` | Operator dashboard |
| `/status/main` | Public status page |
| `/api/uptime/main/summary` | JSON polling API (when `sync: polling`) |
| Hub | Mercure SSE — see [MERCURE.md](MERCURE.md) and [../demo/MERCURE.md](../demo/MERCURE.md) |

Demos use **`dashboard.sync: mercure`** (see `config/packages/nowo_uptime_monitor.yaml`). Each stack includes a `mercure` Docker service. The `checks-worker` container publishes Mercure updates after each check (requires `MERCURE_*` env vars).

| Demo | `MERCURE_PUBLIC_URL` (browser, same origin) |
|------|---------------------------------------------|
| symfony7 | http://localhost:8010/.well-known/mercure |
| symfony8 | http://localhost:8011/.well-known/mercure |

## Development vs production

The demo uses `APP_ENV=dev` with Web Profiler enabled. For production-style FrankenPHP worker mode, see the general pattern in other Nowo bundles' `docs/DEMO-FRANKENPHP.md` (Caddyfile vs Caddyfile.dev, `APP_ENV=prod`).

## Demo monitors (one group + local probes)

`make up` and `make reset-demo` seed **one project group** `[demo]` and **two HTTP monitors** against the demo app (`http://php` from the checks worker):

| Group | Monitors |
|-------|----------|
| `[demo]` | `demo_uptime_ok` → `/demo/uptime/ok`, `demo_uptime_flaky` → `/demo/uptime/flaky/3` |

```bash
make -C demo reset-demo-symfony8
```

Runs `nowo:uptime:seed-demo --fresh` (removes old monitors, recreates tree) and `nowo:uptime:run-due-checks`.

## Reset check history only

Wipe operational data (checks, aggregates, incidents) while keeping monitors:

```bash
make -C demo clear-data-symfony8
```

Optional tenant scope: `docker compose exec php php bin/console nowo:uptime:clear-data -t main --no-interaction`

## Troubleshooting

- **DNS / Composer**: demo compose sets `dns: [8.8.8.8, 8.8.4.4]` on the PHP service for Docker/WSL resolver issues.
- **Ping checks**: ICMP may fail inside the container without `CAP_NET_RAW` or `ping` installed; HTTP/TCP/DNS/SSL monitors still work.
- **Schema**: after pulling bundle updates, run `php bin/console nowo:uptime:sync-schema` in the demo container.
