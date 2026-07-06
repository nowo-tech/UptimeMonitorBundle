# Spec-driven development

In this repository, **spec-driven development** has two layers:

1. **Product behavior** — synthetic uptime monitoring (see [USAGE.md](USAGE.md), [CONFIGURATION.md](CONFIGURATION.md), [INSTALLATION.md](INSTALLATION.md)).
2. **Traceability** — stable **`REQ-*`** identifiers in Makefiles and demos aligned with [BUNDLES_FULL_SPECS_CHECKLIST.md](../BUNDLES_FULL_SPECS_CHECKLIST.md).

Tests and static analysis are the mechanical proof alongside this document.

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

**In scope:** check runners, scheduler, Doctrine storage, dashboard, status page, APIs, notifications, multi-tenant CRUD.

**Non-goals (later):** Mercure real-time sync (P6), advanced status-page branding.

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

## See also

- [USAGE.md](USAGE.md)
- [CONFIGURATION.md](CONFIGURATION.md)
- [CONTRIBUTING.md](CONTRIBUTING.md)
- [RELEASE.md](RELEASE.md)
- [ENGRAM.md](ENGRAM.md)
