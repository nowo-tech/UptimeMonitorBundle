# Installation

## Table of contents

- [Composer](#composer)
- [Symfony Flex recipe](#symfony-flex-recipe)
- [Manual setup](#manual-setup)

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

Build frontend assets when developing the bundle from source (pnpm + Vite).

Configure Doctrine and create tables:

```bash
php bin/console nowo:uptime:sync-schema
php bin/console nowo:uptime:seed-demo   # optional sample monitors
php bin/console nowo:uptime:run-due-checks
```

See [CONFIGURATION.md](CONFIGURATION.md).
