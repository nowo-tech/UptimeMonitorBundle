# Demo applications with FrankenPHP

**REQ-DEMO-001:** FrankenPHP demos must install **Nowo Twig Inspector** and **Nowo Hot Reload** together (`nowo-tech/twig-inspector-bundle` + `nowo-tech/hot-reload-bundle` in `require-dev`). Caddyfile: Mercure + `hot_reload` (and `worker { file …; watch }` in worker mode). Do not enable Hot Reload in production.

## Table of contents

- [Overview](#overview)
- [Quick start](#quick-start)
- [URLs](#urls)
- [Development vs production](#development-vs-production)
- [Demo monitors (one group + local probes)](#demo-monitors-one-group--local-probes)
- [Reset check history only](#reset-check-history-only)
- [Switching classic vs worker (`FRANKENPHP_MODE`)](#switching-classic-vs-worker-frankenphp_mode)
- [Timeouts](#timeouts)
- [Demo smoke (CI)](#demo-smoke-ci)
- [Troubleshooting](#troubleshooting)

## Overview

- **FrankenPHP** (Caddy + PHP) in Docker, with the **built-in Mercure hub** (dashboard live updates + `hot_reload`)
- **MySQL 8.4** (`database` service) on the internal Docker network only — no host port is published (REQ-DEMO-006)
- A `checks-worker` container that runs `nowo:uptime:run-due-checks` every 15 s
- Bundle mounted from the repository root for live code changes
- Default URL: **http://localhost:8011** (see `PORT` in `demo/symfony8/.env.example`)

## Quick start

From the bundle root:

```bash
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

Demos use **`dashboard.sync: mercure`** (see `config/packages/nowo_uptime_monitor.yaml`). The hub is the one built into the demo FrankenPHP server (`mercure` block of both Caddyfiles): PHP — the web app and the `checks-worker` container — publishes to `MERCURE_URL=http://php/.well-known/mercure` on the Compose network, and the browser subscribes on the same origin as the app. There is no separate Mercure container.

| Demo | `MERCURE_PUBLIC_URL` (browser, same origin) |
|------|---------------------------------------------|
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

## Switching classic vs worker (`FRANKENPHP_MODE`)

Demos select the FrankenPHP runtime via **`FRANKENPHP_MODE`** in `.env` / `.env.example` (not a Dockerfile `ENV`):

| Value | Behaviour |
| --- | --- |
| **`worker`** (default) | `docker/frankenphp/Caddyfile` (baked into the image): `php_server { hot_reload; worker { file …; watch } }` |
| **`classic`** | Entrypoint copies `docker/frankenphp/Caddyfile.dev` (mounted): plain `php_server { hot_reload }` |

Both Caddyfiles declare the Mercure hub in the site block: FrankenPHP's `hot_reload` refuses to start without one
(`unable to enable hot reloading: no Mercure hub configured`), which used to break `FRANKENPHP_MODE=classic`. `make up`
runs `docker compose up -d --build`, so edits to the worker Caddyfile reach the (layer-cached) image.

Compose passes `FRANKENPHP_MODE=${FRANKENPHP_MODE:-worker}` into the PHP service. After changing `.env`, run `docker compose up -d` (or `make up`) so the container is **recreated** — a plain `restart` does not reload env. No image rebuild is required.

## Timeouts

REQ-RUNTIME-001 hierarchy of the demo (innermost first). Both Caddyfiles set the PHP and Caddy layers:

| Layer | Demo value | Notes |
|-------|------------|-------|
| Monitor checks | HTTP: monitor timeout (+2 s `max_duration`); TCP/SSL 10 s; ping ≤ 30 s | Run in `checks-worker` (CLI), never in a web request — see [CONFIGURATION.md → Timeouts](CONFIGURATION.md#timeouts-frankenphp-safe-req-runtime-001) |
| Webhook / Slack | `notifications.http_timeout` (10 s) | Same CLI process as the checks |
| PHP `max_execution_time` / `max_input_time` | 60 s (`frankenphp { php_ini … }`) | Web requests only read the database |
| Caddy `servers.timeouts.write` | 75 s | Strictly greater than PHP |
| FrankenPHP `max_wait_time` | 30 s | A request waiting longer for a free PHP thread gets 504 |

The Mercure SSE route is excluded from `encode` (compression breaks the chunked event stream); long-lived SSE
connections are served by Caddy's Go hub, not by PHP threads.

## Demo smoke (CI)

REQ-TEST-011: `make demo-smoke` (root) → `make -C demo demo-smoke` boots `demo/symfony8` (`make up`: build, Composer,
schema, seed), polls **`http://127.0.0.1:8011/uptime/main`** (redirects followed) until it answers **HTTP 200** (90 s
max), prints the PHP logs on failure and always tears the stack down. It runs on every push / pull request (`ci.yml`
job *Demo smoke (HTTP 200)*), nightly and on release tags (`demo-smoke.yml`), and `make release-check` verifies
`/uptime/main` and `/status/main` through `release-check-demos`. Only `.env.example` placeholder values are used.

## Troubleshooting

- **DNS / Composer**: demo compose sets `dns: [8.8.8.8, 8.8.4.4]` on the PHP service (with a comment, REQ-DEMO-009) for Docker/WSL resolver issues.
- **Database port**: MySQL is not published on the host; use `docker compose exec database mysql -uapp -papp app`. The former Flex leftover `compose.override.yaml` (it published `5432` / `80`) was removed — do not re-add Flex Docker recipes to the demo.
- **Ping checks**: ICMP may fail inside the container without `CAP_NET_RAW` or `ping` installed; HTTP/TCP/DNS/SSL monitors still work.
- **Schema**: after pulling bundle updates, run `php bin/console nowo:uptime:sync-schema` in the demo container.
