# Spec-driven development

In this repository, **spec-driven development** has three layers that stay in sync:

1. **GitHub Spec Kit baseline** — [`specs/001-baseline/`](../specs/001-baseline/) ([`spec.md`](../specs/001-baseline/spec.md), [`code-inventory.md`](../specs/001-baseline/code-inventory.md)), initialized with [GitHub Spec Kit](https://github.com/github/spec-kit) (`.specify/`, **Cursor Agent** skills in `.cursor/skills/speckit-*`). The inventory maps **100%** of production code in `src/`. **How to install, initialize, and use Spec Kit:** [`SPEC-KIT.md`](SPEC-KIT.md).
2. **Product behavior** — synthetic uptime monitoring (see [USAGE.md](USAGE.md), [CONFIGURATION.md](CONFIGURATION.md), [INSTALLATION.md](INSTALLATION.md)). **PHPUnit** and **PHPStan** enforce contracts in CI where applicable.
3. **Traceability anchors** — stable **`REQ-*`** identifiers in Makefiles and demos aligned with [BUNDLES_FULL_SPECS_CHECKLIST.md](../BUNDLES_FULL_SPECS_CHECKLIST.md).

There is no separate executable spec language (for example Gherkin); Spec Kit specs, tests, and static analysis are the mechanical proof alongside this document.

## User stories

| ID | Story |
| --- | --- |
| US-01 | As an operator I configure HTTP(S)/TCP/DNS/SSL/Ping monitors and the scheduler runs checks on interval |
| US-02 | As an operator I see monitor status in a dashboard with polling sync |
| US-03 | As an operator I retain detail for N days and aggregates forever |
| US-04 | As a platform team I isolate monitors per tenant slug |
| US-05 | As an operator I receive alerts on status change (email/webhook/Slack) |
| US-06 | As an end user I view a public status page per tenant |
| US-07 | As a maintainer I run demos on Symfony 7 and 8 with FrankenPHP |

## Functional scope

**In scope:** check runners, scheduler, Doctrine storage, dashboard (polling **or Mercure** sync via `dashboard.sync`), status page, APIs, notifications, multi-tenant CRUD.

**Non-goals (later):** advanced status-page white-label branding beyond tenant appearance settings.

## Validating the spec

```bash
make release-check
# or
composer qa && composer test-coverage-90
```

- PHPUnit: `tests/Unit`, `tests/Integration`
- Vitest: `src/Resources/assets/src/*.test.ts`
- Demo smoke: `make -C demo release-check`

## Requirement identifiers (`REQ-*`)

| ID | Where | What it marks |
| --- | --- | --- |
| REQ-DEMO-005 | `demo/symfony7/Makefile`, `demo/symfony8/Makefile` | `make up` prints demo URL from `PORT` |
| REQ-DEMO-007 | `demo/*/Makefile` `update-bundle` | Demo syncs path-mounted bundle |
| REQ-TEST-006 | `Makefile` `test-coverage-90` | Coverage threshold ≥90% |
| REQ-MAKE-002 | `Makefile` `release-check` | Pre-release QA chain |

## Suggested workflow for contributors

1. **Clarify behavior** in an issue or draft PR: acceptance criteria for the **product** and, if relevant, **Makefiles/demos** (`REQ-*`).
2. **Implement** with tests and static analysis.
3. **Anchor scripts and demos** when dev UX changes: add or adjust `REQ-*` comments and the requirement table.
4. **Ship integrator docs** when behavior or configuration changes: [`USAGE.md`](USAGE.md), [`CONFIGURATION.md`](CONFIGURATION.md), [`CHANGELOG.md`](CHANGELOG.md), and [`UPGRADING.md`](UPGRADING.md) when consumers must change code or config.
5. **Keep Spec Kit artifacts in sync** when production code under `src/` changes:
   - Update [`specs/001-baseline/spec.md`](../specs/001-baseline/spec.md) and [`code-inventory.md`](../specs/001-baseline/code-inventory.md).
   - Follow the maintainer checklist in [`SPEC-KIT.md`](SPEC-KIT.md).
   - For **new features**, use Cursor Agent skills (`/speckit-specify`, `/speckit-plan`, `/speckit-tasks`) as documented in SPEC-KIT.

---


## GitHub Spec Kit (summary)

This repository uses [GitHub Spec Kit](https://github.com/github/spec-kit) with **Cursor Agent** (`cursor-agent` integration).

| Artifact | Path |
| --- | --- |
| **Operator manual** (install, init, usage) | [`SPEC-KIT.md`](SPEC-KIT.md) |
| Baseline spec | [`specs/001-baseline/spec.md`](../specs/001-baseline/spec.md) |
| Code inventory (100%) | [`specs/001-baseline/code-inventory.md`](../specs/001-baseline/code-inventory.md) |
| Constitution | [`.specify/memory/constitution.md`](../.specify/memory/constitution.md) |
| Cursor Agent skills | [`.cursor/skills/`](../.cursor/skills/) (`speckit-*`) |

**Quick start (maintainers):**

```bash
# Install Specify CLI (once per machine) — see SPEC-KIT.md
specify init --here --force --integration cursor-agent --script sh
specify integration list   # Cursor → installed (default)
```

In Cursor Agent, start a new feature with `/speckit-specify <description>`. For day-to-day tooling details, skills reference, folder layout, and troubleshooting, read **[`SPEC-KIT.md`](SPEC-KIT.md)**.

---

## See also

- [`SPEC-KIT.md`](SPEC-KIT.md) — GitHub Spec Kit manual (install, structure, usage)
- [USAGE.md](USAGE.md)
- [CONFIGURATION.md](CONFIGURATION.md)
- [CONTRIBUTING.md](CONTRIBUTING.md)
- [RELEASE.md](RELEASE.md)
- [ENGRAM.md](ENGRAM.md)
