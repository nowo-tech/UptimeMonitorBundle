# Uptime Monitor Bundle

[![CI](https://github.com/nowo-tech/UptimeMonitorBundle/actions/workflows/ci.yml/badge.svg)](https://github.com/nowo-tech/UptimeMonitorBundle/actions/workflows/ci.yml)
[![Packagist Version](https://img.shields.io/packagist/v/nowo-tech/uptime-monitor-bundle.svg?style=flat)](https://packagist.org/packages/nowo-tech/uptime-monitor-bundle)
[![Packagist Downloads](https://img.shields.io/packagist/dt/nowo-tech/uptime-monitor-bundle.svg)](https://packagist.org/packages/nowo-tech/uptime-monitor-bundle)
[![License](https://img.shields.io/badge/license-MIT-blue.svg)](LICENSE)
[![PHP](https://img.shields.io/badge/PHP-8.2%2B-777BB4?logo=php)](https://php.net)
[![Symfony](https://img.shields.io/badge/Symfony-7.4%2B%20%7C%208.x-000000?logo=symfony)](https://symfony.com)

> ⭐ **Found this useful?** Give it a star on GitHub! It helps us maintain and improve the project.

**Symfony bundle for synthetic uptime monitoring** (Uptime Kuma–style): scheduled checks, detail retention, perpetual aggregates, multi-tenant dashboard with polling sync, public status page, and notifications.

> 📋 **Compatible with Symfony 7.4+ and 8.x** (PHP 8.2+)

## Features

- ✅ HTTP/HTTPS, TCP, DNS, SSL certificate, and Ping (ICMP) checks
- ✅ Symfony Scheduler + `nowo:uptime:run-due-checks`
- ✅ Doctrine entities: tenant, monitor, check result, aggregates, incidents
- ✅ YAML retention (`detail_days`) + aggregates kept forever
- ✅ REST summary API for dashboard polling (`?since=` delta support)
- ✅ Vite + TypeScript dashboard and Chart.js charts
- ✅ Public status page per tenant (`/status/{tenantSlug}`)
- ✅ Email, webhook, Slack notifications (on status change)
- ✅ CRUD for monitors and tenants (Twig UI)
- ✅ Demo Symfony 7 and 8 (FrankenPHP) — see [docs/DEMO-FRANKENPHP.md](docs/DEMO-FRANKENPHP.md)
- ✅ Mercure real-time dashboard sync (`dashboard.sync: mercure`)

## Quick start

```bash
composer require nowo-tech/uptime-monitor-bundle
```

```yaml
# config/packages/nowo_uptime_monitor.yaml
nowo_uptime_monitor:
    retention:
        detail_days: 30
```

```bash
php bin/console nowo:uptime:sync-schema
php bin/console nowo:uptime:seed-demo
php bin/console nowo:uptime:run-due-checks
```

Dashboard: `/uptime/{tenantSlug}` · Status page: `/status/{tenantSlug}` (default tenant `main`).

## Documentation

- [GitHub Actions CI requirements](docs/GITHUB_CI.md)
- [Installation](docs/INSTALLATION.md)
- [Configuration](docs/CONFIGURATION.md)
- [Usage](docs/USAGE.md)
- [Contributing](docs/CONTRIBUTING.md)
- [Code of Conduct](CODE_OF_CONDUCT.md)
- [Changelog](docs/CHANGELOG.md)
- [Upgrading](docs/UPGRADING.md)
- [Release process](docs/RELEASE.md)
- [Security](docs/SECURITY.md)
- [Engram](docs/ENGRAM.md)
- [Spec-driven development](docs/SPEC-DRIVEN-DEVELOPMENT.md)
- [GitHub Spec Kit](docs/SPEC-KIT.md)

### Additional documentation

- [Scheduling](docs/SCHEDULING.md)
- [Check types](docs/CHECK-TYPES.md)
- [Monitor configuration (Uptime Kuma parity)](docs/MONITOR-CONFIGURATION.md)
- [Tenant settings (Uptime Kuma parity)](docs/SETTINGS.md)
- [Translations (en, es)](docs/TRANSLATIONS.md)
- [Notifications](docs/NOTIFICATIONS.md)
- [Mercure real-time sync](docs/MERCURE.md)
- [Demo with FrankenPHP](docs/DEMO-FRANKENPHP.md)

## Development

```bash
make up
make test
make assets
make -C demo up-symfony8
```

## Tests and coverage

| Language | Coverage (approx.) | Command |
|----------|-------------------|---------|
| PHP | 95% | `make test-coverage` |
| TypeScript | Vitest (api-client, poll-controller, chart-theme) | `make test-ts` |
| Python | N/A | — |

```bash
make test
make test-coverage
make test-coverage-90
make test-ts
make release-check
```

## License

MIT — see [LICENSE](LICENSE).
