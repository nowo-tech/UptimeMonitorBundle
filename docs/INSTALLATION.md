- **FormKitBundle** (`nowo-tech/form-kit-bundle` ^2.0) — dashboard/admin Symfony forms (`FormOptionsTrait`, profile `uptime_monitor`). Register `NowoFormKitBundle` in `config/bundles.php` (Flex / demo). Optional host YAML: `config/packages/nowo_form_kit.yaml`.

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

## Twig Extra Bundle (REQ-TWIG-004)

This package ships Twig templates. Host applications **must** install and enable Twig Extra:

```bash
composer require twig/extra-bundle twig/string-extra
```

Register `Twig\Extra\TwigExtraBundle\TwigExtraBundle` in `config/bundles.php` (Flex usually does this). Demos already include the same stack. The package `release-check` runs `make check-twig-extra` to guard this contract.
