- **FormKitBundle** (`nowo-tech/form-kit-bundle` ^2.0) — dashboard/admin Symfony forms (`FormOptionsTrait`, profile `uptime_monitor`). Register `NowoFormKitBundle` in `config/bundles.php` (Flex / demo). Optional host YAML: `config/packages/nowo_form_kit.yaml`.

# Installation

## Table of contents

- [Composer](#composer)
- [Symfony Flex recipe](#symfony-flex-recipe)
- [Manual setup](#manual-setup)
- [Twig Extra Bundle (REQ-TWIG-004)](#twig-extra-bundle-req-twig-004)

## Composer

```bash
composer require nowo-tech/uptime-monitor-bundle
```

## Symfony Flex recipe

When installing via Symfony Flex, the recipe (`.symfony/recipe/nowo-tech/uptime-monitor-bundle/1.0.0/`) will:

- Register `UptimeMonitorBundle` in `config/bundles.php`
- Copy default `config/packages/nowo_uptime_monitor.yaml`
- Import routes via `config/routes/nowo_uptime_monitor.yaml`

Post-install reminder:

```bash
php bin/console nowo:uptime:sync-schema
php bin/console assets:install public
```

## Manual setup

Register the bundle (if Flex did not):

```php
// config/bundles.php
Nowo\UptimeMonitorBundle\UptimeMonitorBundle::class => ['all' => true],
```

Import routes in `config/routes.yaml`:

```yaml
nowo_uptime_monitor:
    resource: '@UptimeMonitorBundle/Resources/config/routes.yaml'
```

Install bundle public assets:

```bash
php bin/console assets:install public
```

Load published CSS/JS with the named Symfony asset package **`nowo_uptime_monitor`** (registered via `framework.assets.packages`, base path `/bundles/uptimemonitor`):

```twig
<link rel="stylesheet" href="{{ asset('uptime-dashboard.css', 'nowo_uptime_monitor') }}">
```

Build frontend assets when developing the bundle from source (pnpm + Vite).

Configure Doctrine and create tables:

```bash
php bin/console nowo:uptime:sync-schema
php bin/console nowo:uptime:seed-demo   # optional sample monitors
php bin/console nowo:uptime:run-due-checks
```

See [CONFIGURATION.md](CONFIGURATION.md).

### Reference DDL of the 1.18 columns

1.18 adds two columns and one unique index to `uptime_monitor` (table name without `doctrine.table_prefix`). Hosts on
Doctrine migrations should get exactly this from `doctrine:migrations:diff`; `nowo:uptime:sync-schema` applies it
otherwise. Existing rows take the default `ui`; there is no backfill.

```sql
-- MySQL / MariaDB
ALTER TABLE uptime_monitor
  ADD origin VARCHAR(8) DEFAULT 'ui' NOT NULL,
  ADD origin_key VARCHAR(64) DEFAULT NULL;
CREATE UNIQUE INDEX uniq_uptime_monitor_origin_key ON uptime_monitor (tenant_id, origin_key);

-- PostgreSQL
ALTER TABLE uptime_monitor ADD origin VARCHAR(8) DEFAULT 'ui' NOT NULL;
ALTER TABLE uptime_monitor ADD origin_key VARCHAR(64) DEFAULT NULL;
CREATE UNIQUE INDEX uniq_uptime_monitor_origin_key ON uptime_monitor (tenant_id, origin_key);

-- SQLite
ALTER TABLE uptime_monitor ADD COLUMN origin VARCHAR(8) DEFAULT 'ui' NOT NULL;
ALTER TABLE uptime_monitor ADD COLUMN origin_key VARCHAR(64) DEFAULT NULL;
CREATE UNIQUE INDEX uniq_uptime_monitor_origin_key ON uptime_monitor (tenant_id, origin_key);
```

Rollback: drop the index, then the two columns (1.17.x does not map them).

## Twig Extra Bundle (REQ-TWIG-004)

This package ships Twig templates. Host applications **must** install and enable Twig Extra:

```bash
composer require twig/extra-bundle twig/string-extra
```

Register `Twig\Extra\TwigExtraBundle\TwigExtraBundle` in `config/bundles.php` (Flex usually does this). Demos already include the same stack. The package `release-check` runs `make check-twig-extra` to guard this contract.
