# Upgrading Guide

This guide helps you upgrade between versions of the Uptime Monitor Bundle.

## Current compatibility baseline

- PHP: `>=8.2 <8.6`
- Symfony components: `^7.4 || ^8.0`

## Table of contents

- [Upgrading to next release (Unreleased)](#upgrading-to-next-release-unreleased)
- [Upgrading to 1.0.6 (2026-07-13)](#upgrading-to-106-2026-07-13)
- [Upgrading to 1.0.5 (2026-07-08)](#upgrading-to-105-2026-07-08)
- [Upgrading to 1.0.4 (2026-07-08)](#upgrading-to-104-2026-07-08)
- [Upgrading to 1.0.3 (2026-07-06)](#upgrading-to-103-2026-07-06)
- [Upgrading to 1.0.2 (2026-07-06)](#upgrading-to-102-2026-07-06)
- [Upgrading to 1.0.1 (2026-07-06)](#upgrading-to-101-2026-07-06)
- [Upgrading to 1.0.0 (2026-07-06)](#upgrading-to-100-2026-07-06)

## Upgrading to next release (Unreleased)

_No changes yet._

## Upgrading to 1.0.6 (2026-07-13)

Maintenance only (`.gitignore`, dev `composer.lock`, demo lock sync). The bundle API, configuration, and runtime behaviour are unchanged.

```bash
composer update nowo-tech/uptime-monitor-bundle
```

No application code or schema changes are required.

## Upgrading to 1.0.5 (2026-07-08)

Demo `composer.lock` and generated `config/reference.php` maintenance only. The bundle API, configuration, and runtime behaviour are unchanged.

```bash
composer update nowo-tech/uptime-monitor-bundle
```

No application code or schema changes are required.

## Upgrading to 1.0.4 (2026-07-08)

Documentation and maintainer tooling only (GitHub Spec Kit baseline). The bundle API, configuration keys, and runtime behaviour are unchanged.

```bash
composer update nowo-tech/uptime-monitor-bundle
```

No application code or schema changes are required.

## Upgrading to 1.0.3 (2026-07-06)

Demo `composer.lock` maintenance only. The bundle API, configuration, and runtime behaviour are unchanged.

```bash
composer update nowo-tech/uptime-monitor-bundle
```

No application code or schema changes are required.

## Upgrading to 1.0.2 (2026-07-06)

Makefile and dev-tooling only. The bundle API, configuration keys, and runtime behaviour are unchanged.

```bash
composer update nowo-tech/uptime-monitor-bundle
```

No application code or schema changes are required.

## Upgrading to 1.0.1 (2026-07-06)

Repository, CI, and test tooling only. The bundle API, configuration keys, and runtime behaviour are unchanged.

```bash
composer update nowo-tech/uptime-monitor-bundle
```

No application code or schema changes are required.

## Upgrading to 1.0.0 (2026-07-06)

Initial public release. There is no earlier tagged version to migrate from.

### What's included

- Public status page (`status_page` config, route `/status/{tenantSlug}` by default).
- Ping (ICMP) check runner and monitor form type.
- API summary supports `?since=` filtering for polling deltas.
- Demo seed: one project group plus local HTTP probe monitors (`demo_uptime_ok`, `demo_uptime_flaky`).
- Uptime Kuma–style **tenant settings** UI (`/uptime/{tenant}/settings`) and extended HTTP monitor options.
- `uptime_tenant.settings` JSON column and `uptime_tag` table.
- Mercure real-time dashboard sync (optional).

### Fresh install

```bash
composer require nowo-tech/uptime-monitor-bundle
php bin/console nowo:uptime:sync-schema
php bin/console assets:install public
```

Optional demo data:

```bash
php bin/console nowo:uptime:seed-demo
php bin/console nowo:uptime:run-due-checks
```

### Optional configuration

Status page:

```yaml
nowo_uptime_monitor:
    status_page:
        enabled: true
        path: /status
```

Mercure sync (requires `symfony/mercure-bundle`):

```yaml
nowo_uptime_monitor:
    dashboard:
        sync: mercure
```

### Notes

- **Ping monitors**: the PHP container/host must have the `ping` binary and (in Docker) often `CAP_NET_RAW`. See [CHECK-TYPES.md](CHECK-TYPES.md).
- **Settings & monitors**: see [SETTINGS.md](SETTINGS.md) and [MONITOR-CONFIGURATION.md](MONITOR-CONFIGURATION.md).
- **HTTP monitors**: private/local targets are blocked by default (SSRF guard). See [SECURITY.md](SECURITY.md).

### Breaking changes

None. This is the first stable release.
