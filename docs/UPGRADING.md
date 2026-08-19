# Upgrading Guide

This guide helps you upgrade between versions of the Uptime Monitor Bundle.

## Current compatibility baseline

- PHP: `>=8.2 <8.6`
- Symfony components: `^7.4 || ^8.0`

## Table of contents

- [Unreleased](#unreleased)
- [To 1.3.3](#to-133)
- [To 1.3.2](#to-132)
- [To 1.3.1](#to-131)
- [To 1.3.0](#to-130)
- [Upgrading to 1.2.0 (2026-08-03)](#upgrading-to-120-2026-08-03)
- [Upgrading to 1.1.1 (2026-07-30)](#upgrading-to-111-2026-07-30)
- [Upgrading to 1.1.0 (2026-07-30)](#upgrading-to-110-2026-07-30)
- [Upgrading to 1.0.11 (2026-07-29)](#upgrading-to-1011-2026-07-29)
- [Upgrading to 1.0.10 (2026-07-22)](#upgrading-to-110-2026-07-22)
- [Upgrading to 1.0.9 (2026-07-22)](#upgrading-to-109-2026-07-22)
- [Upgrading to 1.0.8 (2026-07-16)](#upgrading-to-108-2026-07-16)
- [Upgrading to 1.0.7 (2026-07-13)](#upgrading-to-107-2026-07-13)
- [Upgrading to 1.0.6 (2026-07-13)](#upgrading-to-106-2026-07-13)
- [Upgrading to 1.0.5 (2026-07-08)](#upgrading-to-105-2026-07-08)
- [Upgrading to 1.0.4 (2026-07-08)](#upgrading-to-104-2026-07-08)
- [Upgrading to 1.0.3 (2026-07-06)](#upgrading-to-103-2026-07-06)
- [Upgrading to 1.0.2 (2026-07-06)](#upgrading-to-102-2026-07-06)
- [Upgrading to 1.0.1 (2026-07-06)](#upgrading-to-101-2026-07-06)
- [Upgrading to 1.0.0 (2026-07-06)](#upgrading-to-100-2026-07-06)


## Unreleased

## To 1.3.3

From **1.3.2** — No application upgrade steps.

```bash
composer update nowo-tech/uptime-monitor-bundle
```

## To 1.3.2

From **1.3.1** — Review production config for SSRF hardening. Flex recipe `when@prod` sets:

```yaml
nowo_uptime_monitor:
    checks:
        block_private_urls: true
```

Replace placeholder `url_allowlist` entries before enabling checks against internal targets.

```bash
composer update nowo-tech/uptime-monitor-bundle
php bin/console cache:clear
```

## To 1.3.1

From **1.3.0** — No application upgrade steps. **Demos only:** Hot Reload Bundle `^1.4` (FrankenPHP Mercure/`hot_reload`, `dev`/`test`). Shipped demos are Symfony 8 only (Symfony 6/7 demo apps removed).

```bash
composer update nowo-tech/uptime-monitor-bundle
```

## To 1.3.0

From **1.2.0** — Adds FormKit and/or UiKit where applicable, Twig Extra (REQ-TWIG-004), and Twig-CS-Fixer. Register TwigExtraBundle, NowoFormKitBundle, and NowoUiKitBundle if Flex did not. See CHANGELOG.

```bash
composer update nowo-tech/uptime-monitor-bundle
php bin/console cache:clear
```

### Twig Extra Bundle (REQ-TWIG-004)

Hosts that render this bundle's Twig templates must install:

```bash
composer require twig/extra-bundle twig/string-extra
```

and enable `Twig\Extra\TwigExtraBundle\TwigExtraBundle`. Flex recipes usually register it automatically.

### Twig-CS-Fixer (maintainers)

Package maintainers: `composer twig:lint` / `composer twig:fix` use `.twig-cs-fixer.php` over `src/` (and `templates/` when present).


## Upgrading to 1.2.0 (2026-08-03)

Minor release: REQ-UI-002 completion — `security.access_roles` / `access_checker` / `allow_unauthenticated`, SecurityBundle compile-time guard, and `UptimeMonitorAccessSubscriber` enforcement on admin controllers.

### Install / update

```bash
composer require nowo-tech/uptime-monitor-bundle:^1.2
php bin/console cache:clear
```

### Behaviour change (admin access)

| Topic | Before | 1.2.0 |
| --- | --- | --- |
| General gate | Area `*_roles` only | `access_roles` (default `[ROLE_ADMIN]`) **plus** area roles |
| Enforcement | Twig / host firewall | Bundle `UptimeMonitorAccessSubscriber` on admin controllers |
| Apps without SecurityBundle | Could boot | Boot fails with `LogicException` unless `allow_unauthenticated: true` |

**Demos / trusted local kernels:**

```yaml
nowo_uptime_monitor:
    security:
        access_roles: []
        allow_unauthenticated: true   # never in production
        dashboard_roles: []
        manage_roles: []
        settings_roles: []
```

**Production:** keep `allow_unauthenticated: false`, install SecurityBundle, grant `access_roles` (and area roles), and protect `/uptime` with host `access_control`.

### Breaking changes

Apps that used the dashboard without SecurityBundle (or without matching `ROLE_ADMIN`) must install/configure SecurityBundle roles or set `allow_unauthenticated: true` for non-production use. The public status page (`/status`) is unchanged.

## Upgrading to 1.1.1 (2026-07-30)

### Behaviour change (backup import CSRF)


Settings backup **import** POST now requires a CSRF token (REQ-SEC-005), same pattern as history purge / clear-stats.

| Before | After |
|--------|--------|
| Import accepted without `_token` | Import ignored unless `_token` is valid for id `backup-import` |

If you POST to `nowo_uptime_settings_backup` with a custom form (not the bundle Twig), add:

```html
<input type="hidden" name="_token" value="{{ csrf_token('backup-import') }}">
```

The bundled `settings/backup.html.twig` already includes this field.

```bash
composer update nowo-tech/uptime-monitor-bundle
php bin/console cache:clear
```

## Upgrading to 1.1.0 (2026-07-30)

### REQ-UI-001 aliases and host layout stacking (optional)

**Non-breaking.** Existing `templates.layout` / `ui.framework` config keeps working. New optional aliases:

| Prefer (canonical) | Also accepted |
|--------------------|---------------|
| `templates.layout` | `templates.layout_template` |
| `ui.framework` | `ui.css_framework` |

Twig: `nowo_uptime_layout_template` mirrors `uptime_layout`. Prefer pointing `templates.layout` at your project layout instead of freezing every admin page (see [USAGE](USAGE.md#overriding-templates)).

```bash
composer require nowo-tech/uptime-monitor-bundle:^1.1.0
php bin/console cache:clear
```

## Upgrading to 1.0.11 (2026-07-29)

### Security defaults (UI-002)

Default `nowo_uptime_monitor.security.*_roles`, `security.access_roles`, and `dashboard.roles` are **`ROLE_ADMIN`**. `security.allow_unauthenticated` defaults to **`false`**. Admin UI access is enforced by `UptimeMonitorAccessSubscriber`.

To keep previous “allow all” demo behaviour:

```yaml
nowo_uptime_monitor:
    security:
        access_roles: []
        allow_unauthenticated: true   # demo/dev only
        dashboard_roles: []
        manage_roles: []
        settings_roles: []
```

Protect `/uptime` in the host app with Symfony `access_control` (see [CONFIGURATION.md](CONFIGURATION.md) / [SECURITY.md](SECURITY.md)).

### Ping SSRF

ICMP ping targets are blocked when they resolve to private/local networks if `checks.block_private_urls` is `true` (default), same guard as HTTP monitors.

```bash
composer update nowo-tech/uptime-monitor-bundle
php bin/console cache:clear
```

## Upgrading to 1.0.10 (2026-07-22)

Code style / demo lock follow-up to 1.0.9 (`import_symbols`). The bundle public API, configuration keys, and runtime behaviour are unchanged.

```bash
composer update nowo-tech/uptime-monitor-bundle
```

No application code or schema changes are required.

## Upgrading to 1.0.9 (2026-07-22)

Demo FrankenPHP mode switch (`FRANKENPHP_MODE`), demo lock sync, and frontend/dev tooling. The bundle public API, configuration keys, and runtime behaviour are unchanged for application consumers.

```bash
composer update nowo-tech/uptime-monitor-bundle
```

No application code or schema changes are required. If you run the demos, set `FRANKENPHP_MODE=classic|worker` in `.env` and recreate containers (`docker compose up -d`) after changing it.

## Upgrading to 1.0.8 (2026-07-16)

Maintainer/CI tooling, documentation, catalogue key sync, and PHPUnit coverage exclusions. The bundle public API, configuration keys, and runtime behaviour are unchanged for application consumers.

```bash
composer update nowo-tech/uptime-monitor-bundle
```

No application code or schema changes are required. Optional: if you fork or contribute, run `make setup-hooks` once per clone (REQ-GIT-001).

## Upgrading to 1.0.7 (2026-07-13)

Demo `config/reference.php` maintenance only (align with Symfony generator). The bundle API, configuration, and runtime behaviour are unchanged.

```bash
composer update nowo-tech/uptime-monitor-bundle
```

No application code or schema changes are required.

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
### FormKitBundle (admin forms)

If you use admin/dashboard Symfony forms, ensure `nowo-tech/form-kit-bundle` ^2.0 is installed (pulled transitively) and `Nowo\FormKitBundle\NowoFormKitBundle` is registered. Form types use profile `uptime_monitor` via `#[FormKitConfig]`; the bundle prepends that profile when the host has not defined it.
