# Contributing

Thank you for contributing to Uptime Monitor Bundle.

## Code of Conduct

This project follows the [Contributor Covenant Code of Conduct](../CODE_OF_CONDUCT.md). By participating, you are expected to uphold it. Please report unacceptable behavior to **hectorfranco@nowo.tech**.

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

## Git hooks (REQ-GIT-001)

Do **not** add `Co-authored-by: Cursor` or `cursoragent@cursor.com` trailers to commit messages.

```bash
make setup-hooks
make check-no-cursor-coauthor
```

`make setup-hooks` installs `.githooks/commit-msg` (or sets `core.hooksPath` to `.githooks`). Run it once per clone before your first commit.
If CI fails because trailers are already on the remote, see [GITHUB_CI.md](GITHUB_CI.md) (REQ-GIT-001) and run `make strip-cursor-coauthor-from-history` before `git push --force-with-lease`.
