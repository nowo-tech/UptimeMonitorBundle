# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/).

## [Unreleased]

### Added

### Changed

### Fixed

### Removed

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

[1.0.0]: https://github.com/nowo-tech/UptimeMonitorBundle/releases/tag/v1.0.0
