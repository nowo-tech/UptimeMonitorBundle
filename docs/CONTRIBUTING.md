# Contributing

Thank you for contributing to Uptime Monitor Bundle.

## Table of contents

- [Code of Conduct](#code-of-conduct)
- [Development setup](#development-setup)
- [Quality gates](#quality-gates)
- [Coding standards](#coding-standards)
- [Pull requests](#pull-requests)
- [Reporting issues](#reporting-issues)
- [Git hooks (REQ-GIT-001)](#git-hooks-req-git-001)
- [Cursor Agent (REQ-IDE-006)](#cursor-agent-req-ide-006)

## Code of Conduct

This project follows the [Contributor Covenant Code of Conduct](../CODE_OF_CONDUCT.md). By participating, you are expected to uphold it. Please report unacceptable behavior to **hectorfranco@nowo.tech**.

## Development setup

```bash
git clone https://github.com/nowo-tech/UptimeMonitorBundle.git
cd uptime-monitor-bundle
make up
make test
make test-ts
make assets
```

Styles live in `src/Resources/assets/scss/` (Sass). Vite compiles them to `src/Resources/public/*.css`. Edit SCSS, then run `make assets` (or `pnpm run build` inside the PHP container).

Database-backed tests use in-memory SQLite by default (`tests/Support/SqliteEntityManagerFactory`). `make test-with-db`
(and `make test-coverage-with-db`) runs the same suite against the MySQL and PostgreSQL services of the root
`docker-compose.yml` by setting `UPTIME_TEST_DATABASE_URL` from `DATABASE_URL` / `DATABASE_URL_PG` (REQ-TEST-005).

Demos: `make -C demo up-symfony8` (port 8011). `make demo-smoke` boots it and expects HTTP 200 on `/uptime/main`
(REQ-TEST-011; also a CI job).

## Quality gates

Before opening a PR:

```bash
make igor
make release-check
```

This runs code style, PHPStan, Rector dry-run, Igor, `validate-translations`, PHPUnit coverage (≥90%), demo healthchecks, and Vitest.

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

Do **not** add `Co-authored-by: Cursor` or `cursoragent@cursor.com` trailers, nor `Co-Authored-By: Claude …` / `noreply@anthropic.com` ones, to commit messages.

```bash
make setup-hooks
make check-no-cursor-coauthor
```

`make setup-hooks` installs `.githooks/commit-msg` (or sets `core.hooksPath` to `.githooks`). Run it once per clone before your first commit.
If CI fails because trailers are already on the remote, see [GITHUB_CI.md](GITHUB_CI.md) (REQ-GIT-001) and run `make strip-cursor-coauthor-from-history` before `git push --force-with-lease`.

## Cursor Agent (REQ-IDE-006)

Contributors using Cursor Agent on this repository should:

1. **Submit with Ctrl+Enter** (macOS: **⌘+Enter**). **Enter** inserts a newline; **Ctrl+Enter** / **⌘+Enter** submits the prompt. UI: **Cursor Settings → Agents / Chat → Submit with Ctrl + Enter**.
2. **Queue follow-ups** while the agent is running. In user `settings.json` (not a repo file):

```json
{
  "cursor.composer.queueMessageDefaultBehavior": "queue"
}
```

Do **not** use `"steer"` as the org default: steer interrupts the running agent as soon as a follow-up is sent.
