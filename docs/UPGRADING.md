# Upgrading Guide

This guide helps you upgrade between versions of the Uptime Monitor Bundle.

## Table of contents

- [Current compatibility baseline](#current-compatibility-baseline)
- [From 1.3.7 to 1.3.8](#from-137-to-138)
- [From 1.3.6 to 1.3.7](#from-136-to-137)
- [Unreleased](#unreleased)
- [To 1.7.2](#to-172)
- [To 1.7.1](#to-171)
- [To 1.7.0](#to-170)
- [To 1.6.1](#to-161)
- [To 1.6.0](#to-160)
- [To 1.5.0](#to-150)
- [To 1.4.0](#to-140)
- [To 1.3.11](#to-1311)
- [To 1.3.10](#to-1310)
- [To 1.3.9](#to-139)
- [To 1.3.6](#to-136)
- [To 1.3.5](#to-135)
- [To 1.3.4](#to-134)
- [To 1.3.3](#to-133)
- [To 1.3.2](#to-132)
- [To 1.3.1](#to-131)
- [To 1.3.0](#to-130)
  - [Twig Extra Bundle (REQ-TWIG-004)](#twig-extra-bundle-req-twig-004)
  - [Twig-CS-Fixer (maintainers)](#twig-cs-fixer-maintainers)
- [Upgrading to 1.2.0 (2026-08-03)](#upgrading-to-120-2026-08-03)
  - [Install / update](#install--update)
  - [Behaviour change (admin access)](#behaviour-change-admin-access)
  - [Breaking changes](#breaking-changes)
- [Upgrading to 1.1.1 (2026-07-30)](#upgrading-to-111-2026-07-30)
  - [Behaviour change (backup import CSRF)](#behaviour-change-backup-import-csrf)
- [Upgrading to 1.1.0 (2026-07-30)](#upgrading-to-110-2026-07-30)
  - [REQ-UI-001 aliases and host layout stacking (optional)](#req-ui-001-aliases-and-host-layout-stacking-optional)
- [Upgrading to 1.0.11 (2026-07-29)](#upgrading-to-1011-2026-07-29)
  - [Security defaults (UI-002)](#security-defaults-ui-002)
  - [Ping SSRF](#ping-ssrf)
- [Upgrading to 1.0.10 (2026-07-22)](#upgrading-to-1010-2026-07-22)
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
  - [What's included](#whats-included)
  - [Fresh install](#fresh-install)
  - [Optional configuration](#optional-configuration)
  - [Notes](#notes)
  - [Breaking changes](#breaking-changes-1)
  - [FormKitBundle (admin forms)](#formkitbundle-admin-forms)

## Current compatibility baseline

- PHP: `>=8.2 <8.6`
- Symfony components: `^7.4 || ^8.0`

## From 1.3.7 to 1.3.8

No breaking changes. **No application upgrade steps.** Schema sync is more tolerant of already-existing MySQL foreign keys.

```bash
composer update nowo-tech/uptime-monitor-bundle
```

## From 1.3.6 to 1.3.7

No breaking changes. **No application upgrade steps.**

```bash
composer update nowo-tech/uptime-monitor-bundle
```

## Unreleased

## To 1.7.2

From **1.7.1**:

```bash
composer update nowo-tech/uptime-monitor-bundle
```

## To 1.7.1

From **1.7.0**:

```bash
composer update nowo-tech/uptime-monitor-bundle
```

## To 1.7.0

From **1.6.1** — retention purge and clear-data delete in keyset batches.

```bash
composer update nowo-tech/uptime-monitor-bundle
```

- No action needed. The detail purge and `nowo:uptime:clear-data` now delete in batches of `retention.purge_batch_size` rows (default `1000`) instead of one statement; the deleted rows and reported counts are the same.
- Optional: tune the batch size (smaller = shorter locks, more round-trips):

  ```yaml
  nowo_uptime_monitor:
      retention:
          purge_batch_size: 1000
  ```

- Purging is no longer one atomic statement: if it is interrupted, the batches already run stay deleted (run it again to finish).

## To 1.6.1

From **1.6.0** — DNS checks honour block_private_urls (no internal records exposed)

```bash
composer update nowo-tech/uptime-monitor-bundle
```

- With `checks.block_private_urls: true` (default), DNS monitors whose hostname is a private IP literal or `localhost`, or whose answer contains a private/local address, now report **Down** (`… (blocked)`) and no longer store the resolved records. Set `block_private_urls: false` to monitor internal DNS names.

## To 1.6.0

From **1.5.0** — unresolvable ping hosts reported Down; host translator settings no longer forced.

```bash
composer update nowo-tech/uptime-monitor-bundle
```

- The bundle no longer forces `framework.translator.fallbacks: [en]` nor enables the translator. If you relied on that implicit `en` fallback (or on the translator being switched on by the bundle), set `framework.translator` (e.g. `fallbacks: ['en']`) in your application. Bundle translations still load automatically.
- Ping monitors whose host does not resolve now record **Down** (`Could not resolve host …`) instead of running `ping` against the name. No action needed.

## To 1.5.0

From **1.4.0** — host locales no longer forced, enabled switch honoured, never-read keys deprecated, vitest 4.

```bash
composer update nowo-tech/uptime-monitor-bundle
```

- **Locales:** the bundle no longer prepends `framework.default_locale: en` and `framework.enabled_locales: [en, es, de, fr, it, nl, pt]`. Those values merged into the host config (Symfony merges `enabled_locales` lists and the bundle could win on `default_locale`). Set both in your own `config/packages/translation.yaml` / `framework.yaml`; include the locales you want the UI in (bundle translations: en, es, de, fr, it, nl, pt).
- **`enabled: false`** now pauses monitoring (no scheduled checks; `nowo:uptime:run-due-checks` and `RunDueChecksMessage` run nothing). It was ignored before — if you had `enabled: false` set and still expect checks, remove it.
- **Deprecated keys** (never read; setting them now triggers a deprecation): `environments`, `table_prefix`, `aggregates.keep_forever`, `multi_tenant.enabled`, `multi_tenant.header`, `dashboard.prefix`, `dashboard.roles`. Remove them; replacements are listed in [CONFIGURATION.md](CONFIGURATION.md#monitoring-switch-and-deprecated-keys) (`dashboard.roles` → `security.dashboard_roles`).
- Dev dependencies: `symfony/browser-kit`, `dom-crawler` and `mailer` now `^7.4 || ^8.0`; CI no longer runs a Symfony 7.0 cell (composer already required 7.4+).

## To 1.4.0

From **1.3.11** — security: tenant creation gated, SSRF guard hardened, ping host validation.

```bash
composer update nowo-tech/uptime-monitor-bundle
```

- Users with only dashboard roles can no longer create tenants or send unmapped write requests; they need `settings_roles`.
- With `block_private_urls: true` (default), TCP/SSL monitors and HTTP redirects or proxies pointing at private/reserved addresses now report Down. Set `block_private_urls: false` for internal monitoring.
- Ping hosts starting with `-` are rejected.

## To 1.3.11

From **1.3.10** — dependency refresh and repository maintenance.

```bash
composer update nowo-tech/uptime-monitor-bundle
```

- No breaking changes. No application upgrade steps.

## To 1.3.10

From **1.3.9** — asset fix and dependency refresh.

```bash
composer update nowo-tech/uptime-monitor-bundle
php bin/console assets:install public
```

- No breaking changes. Re-run `assets:install` (or your asset-copy step) so the previously missing `assets/monitor-form-modal-CDzwPBdm.js` chunk is published; older hashed chunks in the installed bundle `assets/` directory can be deleted.

## To 1.3.9

From **1.3.8** — REQ-CS-008 Igor FrankenPHP worker audit (igor-php require-dev, igor.json, make igor).

```bash
composer update nowo-tech/uptime-monitor-bundle
php bin/console cache:clear
```

No configuration changes. If your own templates read the Twig globals `uptime_theme` or `uptime_ui_framework` to get the **tenant** theme / UI framework override, switch to the functions `uptime_theme()` and `uptime_ui_framework()`. The globals are now request-independent (configured `ui.framework` and `auto`) so they stay correct in FrankenPHP worker mode without a kernel reset. The bundle layout (`layout.html.twig`, `_stylesheets.html.twig`) still sets a local `uptime_ui_framework` variable from the function, so blocks rendered inside it keep working.
- No application upgrade steps for require-dev Igor tooling (REQ-CS-008). Consumers do not pull `igor-php/igor-php` transitively.

## To 1.3.6

From **1.3.5** — No application upgrade steps (SSRF regression tests only).

```bash
composer update nowo-tech/uptime-monitor-bundle
```

## To 1.3.5

Review Flex recipe `security_nowo_uptime_monitor.yaml` after update.

```bash
composer update nowo-tech/uptime-monitor-bundle
```

## To 1.3.4

From **1.3.3** — Review Flex recipe `security_nowo_uptime_monitor.yaml` access rules after install/update.

```bash
composer update nowo-tech/uptime-monitor-bundle
```

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
