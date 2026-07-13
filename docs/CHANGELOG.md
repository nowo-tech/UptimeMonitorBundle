# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/).

## [Unreleased]

### Added

### Changed

### Fixed

### Removed

## [1.0.7] - 2026-07-13

### Fixed

- Demo `symfony7`/`symfony8`: `config/reference.php` aligned with Symfony generator output (omit `declare(strict_types=1);`) so PHP CS Fixer CI no longer rewrites auto-generated demo config on every run.

## [1.0.6] - 2026-07-13

### Changed

- `.gitignore`: ignore `.cursor/sandbox.json` (machine-specific Cursor sandbox; REQ-IDE-005).
- Dev: `friendsofphp/php-cs-fixer` 3.95.13, `rector/rector` 2.5.6 (`composer.lock`).
- Demo `symfony7` and `symfony8`: `composer.lock` synced after `make update-deps` (path bundle ref to v1.0.5).
- Demo `symfony7`/`symfony8`: `config/reference.php` — restore `declare(strict_types=1);` (PHP CS Fixer CI).

## [1.0.5] - 2026-07-08

### Changed

- Demo `symfony7` and `symfony8`: `composer.lock` synced after `make update-deps` (path bundle ref, `nowo-tech/twig-inspector-bundle` v1.0.34 in demo dev deps).
- Demo `symfony7`/`symfony8`: regenerated `config/reference.php` (Symfony config schema, including `nowo_uptime_monitor.checks.block_private_urls`).

## [1.0.4] - 2026-07-08

### Added

- GitHub Spec Kit integration: `.specify/`, Cursor Agent skills (`.cursor/skills/speckit-*`), and baseline specs under `specs/001-baseline/` (`spec.md`, `code-inventory.md` covering 100% of `src/`).
- [`docs/SPEC-KIT.md`](SPEC-KIT.md) — install, init, structure, and maintainer workflow for Spec Kit.

### Changed

- [`docs/SPEC-DRIVEN-DEVELOPMENT.md`](SPEC-DRIVEN-DEVELOPMENT.md): three-layer model (Spec Kit baseline, product behavior, `REQ-*` traceability) and contributor workflow.
- README: link to GitHub Spec Kit manual in Documentation.
- Dev: `friendsofphp/php-cs-fixer` 3.95.12 (`composer.lock`).

## [1.0.3] - 2026-07-06

### Changed

- Demo `symfony7` and `symfony8`: `composer.lock` synced after `make update-deps` (follow-up to the Makefile fixes in 1.0.2).

## [1.0.2] - 2026-07-06

### Fixed

- `demo/Makefile`: fixed `Makefile.demo-aggregate-update-deps.mk` include (unterminated `abspath` call); `make update-deps` no longer fails at the demo aggregator.
- Demo `symfony7` and `symfony8` Makefiles: set `COMPOSE` and `SERVICE_PHP` so per-demo `make update-deps` runs reliably.

### Changed

- Frontend dev toolchain (Dependabot): Vite 8.1.3, TypeScript 6.0.3, happy-dom 20.10.6, sass 1.101.0, `@types/node` 26.1.0.
- Dev: PHPUnit 11.5.56, Rector 2.5.4.
- CI: `softprops/action-gh-release` v3.

## [1.0.1] - 2026-07-06

### Fixed

- Unit tests aligned with current service and controller APIs (`MonitorUrlSsrfGuard`, `MonitorRetryService`, `DashboardViewBuilder`, `TenantRepository` in retention services, and related constructors).
- CI Symfony **8.1** matrix: dependency resolution when `doctrine/doctrine-bundle` 2.x conflicted with Symfony 8 components.

### Changed

- Dev dependency: `symfony/mercure` bumped to `^0.7.2` (Symfony 8 compatible).
- CI: pins all bundle Symfony components to the matrix version; uses `doctrine/doctrine-bundle` ^3.2 on Symfony 8.x and ^2.18 on Symfony 7.x.
- CI: Dependabot bumps (`actions/cache` v6, `codecov/codecov-action` v7).

## [1.0.0] - 2026-07-06

First stable release of `nowo-tech/uptime-monitor-bundle`.

### Added

- Synthetic check runners: HTTP/HTTPS, TCP, DNS, SSL certificate, and Ping (ICMP).
- Symfony Scheduler integration and `nowo:uptime:run-due-checks` command.
- Doctrine persistence: tenants, monitors, check results, perpetual aggregates, and incidents.
- YAML retention (`detail_days`) with optional purge; aggregates kept forever (`nowo:uptime:rollup`, `nowo:uptime:purge-detail`).
- Multi-tenant dashboard at `/uptime/{tenantSlug}` with CRUD for monitors and tenants.
- REST summary API (`GET /api/uptime/{tenantSlug}/summary`) with `?since=` delta polling.
- Vite + TypeScript dashboard assets and Chart.js charts.
- Public read-only status page per tenant (`/status/{tenantSlug}`).
- Notifications on status change: email, webhook, and Slack (with cooldown).
- Uptime Kuma–style tenant **Settings** UI (`/uptime/{tenantSlug}/settings`).
- Mercure real-time dashboard sync (`dashboard.sync: mercure`, optional `symfony/mercure-bundle`).
- UI framework options: Tabler (default), Bootstrap, Tailwind, or custom host theme.
- SSRF mitigation for HTTP monitor targets (`MonitorUrlSsrfGuard`).
- Console commands: `nowo:uptime:sync-schema`, `nowo:uptime:seed-demo`, `nowo:uptime:clear-data`.
- Symfony Flex recipe (`.symfony/recipe/nowo-tech/uptime-monitor-bundle/1.0.0/`).
- Translations for UI and validators (`en`, `es`).
- Demo applications under `demo/symfony7` and `demo/symfony8` (FrankenPHP).
- Makefile targets for QA (`release-check`, tests, coverage, PHPStan, Rector) and `update-deps` (REQ-MAKE-008).
- Documentation: Installation, Configuration, Usage, Scheduling, Check types, Monitor configuration, Settings, Notifications, Mercure, Security, Contributing, Release, Engram, and spec-driven development.

### Requirements

- PHP `>=8.2 <8.6`
- Symfony components `^7.4 || ^8.0`
- Doctrine ORM/DBAL (see `composer.json`)

[1.0.7]: https://github.com/nowo-tech/UptimeMonitorBundle/releases/tag/v1.0.7
[1.0.6]: https://github.com/nowo-tech/UptimeMonitorBundle/releases/tag/v1.0.6
[1.0.5]: https://github.com/nowo-tech/UptimeMonitorBundle/releases/tag/v1.0.5
[1.0.4]: https://github.com/nowo-tech/UptimeMonitorBundle/releases/tag/v1.0.4
[1.0.3]: https://github.com/nowo-tech/UptimeMonitorBundle/releases/tag/v1.0.3
[1.0.2]: https://github.com/nowo-tech/UptimeMonitorBundle/releases/tag/v1.0.2
[1.0.1]: https://github.com/nowo-tech/UptimeMonitorBundle/releases/tag/v1.0.1
[1.0.0]: https://github.com/nowo-tech/UptimeMonitorBundle/releases/tag/v1.0.0
