# Contributing

Thank you for contributing to Uptime Monitor Bundle.

## Development setup

```bash
git clone https://github.com/nowo-tech/uptime-monitor-bundle.git
cd uptime-monitor-bundle
make up
make test
make test-ts
make assets
```

Styles live in `src/Resources/assets/scss/` (Sass). Vite compiles them to `src/Resources/public/*.css`. Edit SCSS, then run `make assets` (or `pnpm run build` inside the PHP container).

Demos: `make -C demo up-symfony7` (port 8010) or `make -C demo up-symfony8` (port 8011).

## Quality gates

Before opening a PR:

```bash
make release-check
```

This runs code style, PHPStan, Rector dry-run, PHPUnit coverage (≥90%), demo healthchecks, and Vitest.

## Coding standards

- PHP 8.1+, attributes (no annotations)
- PHPDoc in English on public APIs
- Match existing naming and structure in `src/`

## Pull requests

- Describe behavior change and link related issues
- Update `docs/CHANGELOG.md` and `docs/UPGRADING.md` when consumers are affected
- Add or adjust tests for changed behavior

## Reporting issues

Use GitHub issues for bugs and features. For security reports, see [SECURITY.md](SECURITY.md).
