# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/).

## Table of contents

- [[Unreleased]](#unreleased)
- [[1.7.0] - 2026-10-14](#170---2026-10-14)
- [[1.6.1] - 2026-10-13](#161---2026-10-13)
- [[1.6.0] - 2026-10-13](#160---2026-10-13)
- [[1.5.0] - 2026-10-13](#150---2026-10-13)
- [[1.4.0] - 2026-10-13](#140---2026-10-13)
- [[1.3.11] - 2026-10-13](#1311---2026-10-13)
- [[1.3.10] - 2026-10-09](#1310---2026-10-09)
- [[1.3.9] - 2026-09-27](#139---2026-09-27)
- [[1.3.8] - 2026-09-07](#138---2026-09-07)
- [[1.3.7] - 2026-08-24](#137---2026-08-24)
- [[1.3.6] - 2026-08-20](#136---2026-08-20)
- [[1.3.5] - 2026-08-19](#135---2026-08-19)
- [[1.3.4] - 2026-08-19](#134---2026-08-19)
- [[1.3.3] - 2026-08-19](#133---2026-08-19)
- [[1.3.2] - 2026-08-19](#132---2026-08-19)
- [[1.3.1] - 2026-08-18](#131---2026-08-18)
- [[1.3.0] - 2026-08-04](#130---2026-08-04)
- [[1.2.0] - 2026-08-03](#120---2026-08-03)
- [[1.1.1] - 2026-07-30](#111---2026-07-30)
- [[1.1.0] - 2026-07-30](#110---2026-07-30)
- [[1.0.11] - 2026-07-29](#1011---2026-07-29)
- [[1.0.10] - 2026-07-22](#1010---2026-07-22)
- [[1.0.9] - 2026-07-22](#109---2026-07-22)
- [[1.0.8] - 2026-07-16](#108---2026-07-16)
- [[1.0.7] - 2026-07-13](#107---2026-07-13)
- [[1.0.6] - 2026-07-13](#106---2026-07-13)
- [[1.0.5] - 2026-07-08](#105---2026-07-08)
- [[1.0.4] - 2026-07-08](#104---2026-07-08)
- [[1.0.3] - 2026-07-06](#103---2026-07-06)
- [[1.0.2] - 2026-07-06](#102---2026-07-06)
- [[1.0.1] - 2026-07-06](#101---2026-07-06)
- [[1.0.0] - 2026-07-06](#100---2026-07-06)

## [Unreleased]

## [1.7.0] - 2026-10-14

### Changed

- **Retention purge in keyset batches:** `nowo:uptime:purge-detail`, **Settings → Monitor history → Purge** and the scheduled purge no longer run one unbounded `DELETE` over `uptime_check_result`. Expired rows are removed in batches (`SELECT id … AND id > :afterId ORDER BY id` with the platform limit, then `DELETE … WHERE id IN (…)`), each a short autocommit statement, so large check-detail tables are not locked and the binlog is not flooded. Same rows deleted, same counts and command output.
- `nowo:uptime:clear-data` deletes check results, aggregates and incidents with the same batches.
- Maintenance: applied pending Rector rewrites (null-coalescing assignment, arrow-function return type, non-static local call, early return) in `RollupCommand`, `CheckResultRepository`, `AggregateChartService`, `SummaryPayloadBuilder` and two tests; no behaviour change.

### Added

- `retention.purge_batch_size` (default `1000`, min `1`): rows per batch for the detail purge and clear-data.

[1.7.0]: https://github.com/nowo-tech/UptimeMonitorBundle/releases/tag/v1.7.0

## [1.6.1] - 2026-10-13

### Security

- **SSRF / DNS:** DNS monitors now honour `checks.block_private_urls` (default `true`), like HTTP/TCP/SSL/ping. Private IP literals, `localhost`/`*.localhost` and metadata hostnames are rejected before the lookup, and an answer with a private/local A/AAAA address or a blocked CNAME/MX target is reported **Down** (`… (blocked)`) without storing or showing the resolved records. Previously any user able to create monitors could map internal (split-horizon) resolution and DNS-rebinding names through the dashboard.

[1.6.1]: https://github.com/nowo-tech/UptimeMonitorBundle/releases/tag/v1.6.1

## [1.6.0] - 2026-10-13

### Fixed

- Ping checks report **Down** (`Could not resolve host …`) when DNS returns no addresses and private-URL blocking is on, like TCP/SSL. They no longer fall back to pinging the hostname, which re-resolved it after the SSRF check (DNS rebinding).
- `prepend()` no longer prepends `framework.translator` (`paths`, `fallbacks: [en]`): it overrode the host's fallback order and enabled the translator when the host had not configured it. The bundle catalogue is auto-discovered (same change as UiKit 1.14).

[1.6.0]: https://github.com/nowo-tech/UptimeMonitorBundle/releases/tag/v1.6.0

## [1.5.0] - 2026-10-13

### Fixed

- `prepend()` no longer forces `framework.default_locale: en` and `framework.enabled_locales` on the host (they merged into the application's locale setup). Asset package, translator path and `en` fallback are still prepended.
- Root `enabled` option is now honoured: `false` pauses monitoring (scheduler provides no schedule; `DueChecksRunner` — used by `nowo:uptime:run-due-checks` and `RunDueChecksMessage` — executes nothing). UI and data stay available.

### Deprecated

- Never-read options now trigger a deprecation when set (removal in the next major): `environments`, `table_prefix` (tables are always `uptime_*`), `aggregates.keep_forever` (aggregates are always kept), `multi_tenant.enabled`, `multi_tenant.header` (tenant comes from the URL), `dashboard.prefix` (use `dashboard.path`), `dashboard.roles` (use `security.dashboard_roles`). `docs/CONFIGURATION.md` documents the replacements.

### Changed

- CI: drop the Symfony 7.0 matrix cell (composer requires `^7.4`); the coverage step / job run on Symfony 7.4. Dev constraints `symfony/browser-kit`, `dom-crawler`, `mailer` aligned from `^6.3 || ^7.0 || ^8.0` to `^7.4 || ^8.0`.
- Dev: `vitest` and `@vitest/coverage-v8` `^2.1.9` → `^4.1.11` (pnpm lock regenerated; config unchanged, shipped assets untouched). Vitest 4's AST-based V8 remapping counts differently: the reported TS summary moved from 16.98% to 6.31% (min metric) with the same 9 tests; no TS threshold is configured.

[1.5.0]: https://github.com/nowo-tech/UptimeMonitorBundle/releases/tag/v1.5.0

## [1.4.0] - 2026-10-13

### Security

- **Access control:** creating tenants (`TenantController::new`, GET form and POST) now requires `canManageSettings` (`security.settings_roles`) instead of dashboard access; any other unsafe-method (POST/PUT/PATCH/DELETE) request to an unmapped protected action fails closed to `canManageSettings`.
- **SSRF:** `MonitorUrlSsrfGuard` now blocks `IpUtils::PRIVATE_SUBNETS` plus multicast/broadcast/IETF ranges (adds `0.0.0.0/8`, `100.64.0.0/10`, `fc00::/7`, IPv4-mapped IPv6 such as `::ffff:127.0.0.1`, `::`, `*.localhost`, decimal IPv4 forms) and checks every A/AAAA record.
- **SSRF:** with `checks.block_private_urls: true`, HTTP checks use `NoPrivateNetworkHttpClient` (each redirect hop and the connected IP are re-validated, defeating redirect and DNS-rebinding bypasses) and reject a per-monitor `proxy` on a private network.
- **SSRF:** TCP and SSL checks now honour `checks.block_private_urls`; TCP/SSL/ping connect to the validated, pinned address (SSL keeps SNI/name verification via `peer_name`).
- **Ping:** hosts starting with `-` are rejected (`Invalid ping host`) to prevent option injection into the `ping` binary.

[1.4.0]: https://github.com/nowo-tech/UptimeMonitorBundle/releases/tag/v1.4.0

## [1.3.11] - 2026-10-13

### Changed

- Development: `composer.json` pins `config.platform.php` to 8.2.0 so the committed lock stays installable on the minimum PHP; CI overrides the platform per matrix cell.
- Repository: `.twig-cs-fixer.cache` is now git-ignored.

### Dependencies

- `symfony/mercure` 0.8.0 → 0.9.0 (require-dev constraint + development lock; #97).
- `@types/node` 26.6.4 → 26.6.5 (frontend tooling; #98).
- `nowo-tech/ui-kit-bundle` 1.9.2 → 1.11.0 (development lock; #99).
- `nowo-tech/form-kit-bundle` 2.6.1 → 2.7.1 (development lock; #100).
- `doctrine/orm` 3.7.4 → 3.7.5 (development lock; #101).
- `happy-dom` 20.14.5 → 20.14.6 (frontend tooling; #102).
- `vite` 8.3.2 → 8.3.4 (frontend tooling; #103).

[1.3.11]: https://github.com/nowo-tech/UptimeMonitorBundle/releases/tag/v1.3.11

## [1.3.10] - 2026-10-09

### Changed

- **Doctrine ORM SortDirection:** replace string `'ASC'`/`'DESC'` in `#[ORM\OrderBy]` and QueryBuilder `orderBy`/`addOrderBy` with `SortDirection::Ascending`/`Descending` (doctrine/orm deprecation, https://github.com/doctrine/orm/issues/11313); require `doctrine/orm` `^3.7` where applicable.

### Fixed

- **Assets:** the published bundle now ships `assets/monitor-form-modal-CDzwPBdm.js`, the chunk that `uptime-dashboard.js` / `uptime-monitor-detail.js` import for the monitor form modal (it was missing from the 2026-10-06 rebuild). Unreferenced hashed chunks from older builds were removed.
- **Dashboard:** dropped an always-true Mercure topic `!== null` check (PHPStan 2.3); behaviour unchanged.

### Dependencies

- Dependabot: `doctrine/orm` 3.7.2, `doctrine/dbal` 4.5.0, `nowo-tech/form-kit-bundle` 2.5.3, `nowo-tech/ui-kit-bundle` 1.8.5, `igor-php/igor-php` `^0.10.0` (dev), `friendsofphp/php-cs-fixer` 3.95.27, phpstan group, `nowo-tech/phpstan-frankenphp` 1.2.1; JS dev tooling `vite` 8.3.2, `sass` 1.105.1, `happy-dom` 20.14.5, `@types/node` 26.6.4 (assets rebuilt).
- Composer refresh: `nowo-tech/form-kit-bundle` 2.6.1, `nowo-tech/ui-kit-bundle` 1.9.2, `doctrine/orm` 3.7.4, Symfony 7.4.20 components (lockfile); dev tooling `phpstan/phpstan` 2.3.1, `rector/rector` 2.7.0.
- Demo: Symfony 8.1.8, `doctrine/orm` 3.7.4, `doctrine/dbal` 4.5.0, `twig/twig` 3.30.0; regenerated `config/reference.php`.

[1.3.10]: https://github.com/nowo-tech/UptimeMonitorBundle/releases/tag/v1.3.10

## [1.3.9] - 2026-09-27

### Added

- **REQ-CS-008:** `igor-php/igor-php` (require-dev only), root `igor.json`, Composer/`Makefile` `igor` target, and `release-check` wiring for FrankenPHP worker-state audit.

### Changed

- **Worker safety (Igor):** justified `// @igor-ignore` annotations and/or `ResetInterface` / request-scoped fixes so `make igor` passes on package `src/`.

### Fixed

- **FrankenPHP worker mode (no kernel reset):** the `uptime_theme` / `uptime_ui_framework` Twig globals no longer depend on the current tenant (Twig caches globals for the whole worker). New Twig functions `uptime_theme()` and `uptime_ui_framework()` resolve the tenant values per request (memoized per `Request`, `UptimeUiExtension` implements `ResetInterface`); bundle templates use them.
- **FrankenPHP worker mode:** new `ClosedEntityManagerSubscriber` resets a closed Doctrine manager before bundle routes run, and `MonitorBackupService::import()` resets a closed manager when the flush fails (new optional `ManagerRegistry` argument), so a failed flush no longer breaks later requests of the same worker.
- Fixed PHPStan findings (0 errors at level 8).

[1.3.9]: https://github.com/nowo-tech/UptimeMonitorBundle/releases/tag/v1.3.9

## [1.3.8] - 2026-09-07

### Fixed

- **Schema sync:** treat MySQL `duplicate foreign key` errors as already-applied so idempotent schema sync does not fail when constraints already exist.

### Changed

- **Deps (dev):** phpstan / rector / twig-cs-fixer / php-cs-fixer and frontend tooling bumps.
- **Demos/CI:** stabilize demo-smoke MySQL boot and PDO setup.

### Notes

- **No API or configuration changes** for integrators.

[1.3.8]: https://github.com/nowo-tech/UptimeMonitorBundle/releases/tag/v1.3.8

## [1.3.7] - 2026-08-24

### Changed

- **Demos:** MySQL env policy in FrankenPHP stack (REQ-DEMO-011).
- **QA:** add `phpstan-frankenphp` extension (REQ-CS-005).
- **Docs:** PHP-FIG PSR evaluation (REQ-CS-007).
- **Style:** PHP CS Fixer alignment.

### Notes

- **No API or configuration changes** for integrators unless noted above.

[1.3.7]: https://github.com/nowo-tech/UptimeMonitorBundle/releases/tag/v1.3.7

## [1.3.6] - 2026-08-20

### Tests

- **SSRF regression:** expand `MonitorUrlSsrfGuard` coverage (RFC1918, metadata, IPv6, empty host) and assert HTTP/Ping runners block private targets when `block_private_urls` is enabled (and allow when disabled).

## [1.3.5] - 2026-08-19

### Security

- **Flex recipe:** ship `security_nowo_uptime_monitor.yaml` access rules (REQ-SEC-004). Prefer **`^1.3.5`** over `v1.3.4`.

## [1.3.4] - 2026-08-19

### Security

- **Flex recipe:** `security.access_control` for uptime monitor routes (REQ-SEC-004).

[1.3.4]: https://github.com/nowo-tech/UptimeMonitorBundle/releases/tag/v1.3.4

## [1.3.3] - 2026-08-19

### Security

- **CI:** run `composer audit --locked` after dependency install (REQ-SEC / P3).

[1.3.3]: https://github.com/nowo-tech/UptimeMonitorBundle/releases/tag/v1.3.3

## [1.3.2] - 2026-08-19

### Security

- **Flex recipe `when@prod`:** enforce `checks.block_private_urls: true` to mitigate SSRF in uptime checks.

[1.3.2]: https://github.com/nowo-tech/UptimeMonitorBundle/releases/tag/v1.3.2

## [1.3.1] - 2026-08-18

### Changed

- **Demos:** pin `nowo-tech/hot-reload-bundle` to `^1.4` with FrankenPHP Mercure/`hot_reload` (`dev`/`test` only).
- **Demos:** Symfony 8 only; Symfony 6/7 demo apps removed.

[1.3.1]: https://github.com/nowo-tech/UptimeMonitorBundle/releases/tag/v1.3.1

## [1.3.0] - 2026-08-04

### Changed

- **FormKitBundle:** depend on [`nowo-tech/form-kit-bundle`](https://github.com/nowo-tech/FormKitBundle) ^2.0. Admin form types use `FormOptionsTrait` + profile `uptime_monitor` (`#[FormKitConfig]`). Extension prepends that profile when missing; form types are tagged `form.type` so `FormOptionsMerger` is injected.

### Added
- **REQ-TWIG-004:** require `twig/extra-bundle` + `twig/string-extra`; `make check-twig-extra` in `release-check`; demos register `TwigExtraBundle`.
- **Twig-CS-Fixer:** `vincentlanglet/twig-cs-fixer`, `.twig-cs-fixer.php`, `composer twig:lint` / `twig:fix`.

### Changed

- **REQ-UI-001-kit:** Twig screens import `@NowoUiKitBundle/macros/ui.html.twig` instead of a local `_ui_macros.html.twig` (deleted). Domain monitor open/edit actions live in `_monitor_actions.html.twig`. Requires `nowo-tech/ui-kit-bundle` `^1.4`. Extension seeds `nowo_ui_kit.css_framework` from `ui.framework` when the host has not configured UiKit.

[1.3.0]: https://github.com/nowo-tech/UptimeMonitorBundle/releases/tag/v1.3.0

## [1.2.0] - 2026-08-03

### Added

- **REQ-UI-002:** canonical `security.access_roles` (default `[ROLE_ADMIN]`), `security.access_checker`, `security.allow_unauthenticated` (default `false`), and `AllowAllUptimeMonitorAccessChecker`.
- Compile-time SecurityBundle guard when the dashboard is enabled and `allow_unauthenticated` is `false`.
- `UptimeMonitorAccessSubscriber` enforces `UptimeMonitorAccessCheckerInterface` on admin/manage/API controllers (public status page remains ungated).
- Demos set `allow_unauthenticated: true` (never copy to production).

### Changed

- `ConfigurableUptimeMonitorAccessChecker` applies the general `access_roles` gate before area `dashboard_roles` / `manage_roles` / `settings_roles`.
- Docs: CONFIGURATION / SECURITY / recipe; demo MERCURE.md translated to English.
- Demo `release-verify`: run `sync-schema` + `reset-demo` before HTTP smoke (avoids 500 on empty DB).
- Vitest: add `text-summary` reporter and `coverage-ts` output dir (TS coverage parse + avoid PHP `coverage/` lock).
- Dev deps (Dependabot): php-cs-fixer 3.95.18, rector 2.5.9, phpstan group, vite 8.2.0, `@types/node`, `actions/stale` v11, `phpstan-frankenphp` 1.0.2, symfony/phpunit-bridge.

### Compatibility

- PHP `>=8.2 <8.6`; Symfony `^7.4 || ^8.0`.
- Dashboard with default security settings requires **SecurityBundle** (or set `allow_unauthenticated: true` for trusted local demos).

## [1.1.1] - 2026-07-30

### Fixed

- **Security (REQ-SEC-005):** settings backup import POST now validates CSRF (`backup-import`), matching purge-history / clear-stats.
- **Demo / FrankenPHP** — entrypoint waits for `vendor/autoload_runtime.php` before starting worker mode so `make demo-smoke` / `make up` work on a clean CI checkout (workers no longer exit before Composer).

## [1.1.0] - 2026-07-30

### Added

- **REQ-UI-001 naming aliases (BC):** `templates.layout_template` ≡ `templates.layout`; `ui.css_framework` ≡ `ui.framework`. Twig global alias **`nowo_uptime_layout_template`** (same value as `uptime_layout`).
- Unit coverage for **`TwigPathsPass`** (addPath / prependPath override directory).

### Changed

- Admin pages (`dashboard`, `monitor/form`, `monitor/show`) stack CSS/JS with **`{{ parent() }}`** so host layouts keep their assets when `templates.layout` points at the project (REQ-UI-001).
- Default **`layout.html.twig`**: stylesheets / javascripts live inside blocks so `parent()` stacking works.

### Documentation

- **[USAGE](USAGE.md):** Overriding templates (REQ-TWIG-001) — freeze rule, procedure, correct `NowoUptimeMonitorBundle` override path, layout / CSS stack table.
- **[CONFIGURATION](CONFIGURATION.md):** document layout / `css_framework` aliases and `nowo_uptime_layout_template`.
- **[UPGRADING](UPGRADING.md)** section **Upgrading to 1.1.0**.

## [1.0.11] - 2026-07-29

### Added

- FrankenPHP Friendly banner + canonical README sentence (REQ-DOCS-017).
- Root **`make demo-smoke`** + `.github/workflows/demo-smoke.yml` (REQ-TEST-011).
- `.scripts/check-open-prs.sh` in `release-check` (REQ-REL-003 / MAKE-002).
- Composer keywords `php`, `frankenphp` (REQ-PKG-004).
- README badges: GitHub stars + Coverage (REQ-DOCS-004).
- Ping checks honor `MonitorUrlSsrfGuard` / `block_private_urls` (SSRF harden).

### Changed

- **UI-002**: default `security.dashboard_roles` / `manage_roles` / `settings_roles` and `dashboard.roles` → `ROLE_ADMIN` (demos override to `[]` with firewall off).
- **CS-006**: `ignoreErrors: []`; removed `phpstan-baseline.neon`.
- PHPUnit / CI: `SYMFONY_DEPRECATIONS_HELPER=max[direct]=0` (REQ-SF-005).
- Demo Dockerfiles: FrankenPHP **php8.5** (REQ-DEMO-010).
- README Documentation order; `GITHUB_CI` under Additional (REQ-DOCS-002).
- `validate-translations` paths → `NowoUptimeMonitorBundle.*.yaml`.
- Demo Makefiles: absolute `DOCKER_BIN` (REQ-MAKE-010).
- Demos: `dashboard.sync: polling` by default so localhost smoke avoids Mercure cookie domain errors (Mercure still documented).

### Removed

- Stale PHPStan baseline file.

## [1.0.10] - 2026-07-22

### Changed

- Apply PHP CS Fixer `fully_qualified_strict_types.import_symbols` across `src/` and `tests/` (follow-up to 1.0.9 config change; clears stale-cache gap).
- Demo `symfony7`/`symfony8`: `config/bundles.php` import style aligned with CS Fixer; `composer.lock` synced (`doctrine/dbal` 4.4.4, `twig-inspector-bundle` v1.0.37, path bundle ref).
- Dev: `doctrine/dbal` 4.4.4, `doctrine/doctrine-bundle` 2.18.4 (`composer.lock`).

## [1.0.9] - 2026-07-22

### Added

- Demo `symfony7`/`symfony8`: `FRANKENPHP_MODE` (`classic`|`worker`, default `worker`) via `.env` / Compose and shared `docker/entrypoint.sh` (REQ-DEMO-010). Documented in [`DEMO-FRANKENPHP.md`](DEMO-FRANKENPHP.md).

### Changed

- Demo `symfony7`/`symfony8`: `composer.lock` synced (`nowo-tech/twig-inspector-bundle` v1.0.35).
- Frontend dev toolchain (Dependabot): Vite 8.1.5, happy-dom 20.11.0.
- `.php-cs-fixer.dist.php`: `fully_qualified_strict_types.import_symbols` enabled.

### Fixed

- Demo entrypoints: `git safe.directory` for `/var/uptime-monitor-bundle` (was a leftover `/var/otp-input-bundle` path).

## [1.0.8] - 2026-07-16

### Added

- Contributor Covenant [`CODE_OF_CONDUCT.md`](../CODE_OF_CONDUCT.md); linked from README and [CONTRIBUTING](CONTRIBUTING.md).
- [`docs/GITHUB_CI.md`](GITHUB_CI.md): REQ-GIT-001 (no Cursor co-author trailers) — CI job, hooks, and maintainer scripts.
- `.scripts/check-no-cursor-coauthor.sh`, `.scripts/strip-cursor-coauthor-from-history.sh`, `.githooks/commit-msg`, `.cursor/rules/01-git-commits.mdc`.
- Translation keys for public status page and form cancel/loading across `de`, `fr`, `it`, `nl`, `pt` catalogues (new keys fall back to English where not yet localized).
- Unit test: `UptimeMonitorBundle::build()` registers Twig paths compiler pass.

### Changed

- CI: `git-hygiene` job; Dependabot bumps `actions/checkout` v7, `actions/github-script` v9; `@types/node` 26.1.1.
- Dev: `friendsofphp/php-cs-fixer` 3.95.15, `rector/rector` 2.5.7 (`composer.lock`).
- `make release-check` runs `check-no-cursor-coauthor`; `make setup-hooks` installs `commit-msg`.
- PHPUnit Clover gate: exclude Controllers, Forms, Repositories, Commands, Check runners, Services, Entities, and thin wiring from aggregate coverage (VaultBundle policy); covered by integration/E2E.
- [RELEASE](RELEASE.md): re-run `check-no-cursor-coauthor` after the release commit, before push.
- `.php-cs-fixer.dist.php`: exclude demo `config/reference.php` so CS Fixer does not fight the Symfony generator.

## [1.0.7] - 2026-07-13

### Fixed

- Demo `symfony7`/`symfony8`: `config/reference.php` aligned with Symfony generator output (omit `declare(strict_types=1);`) so PHP CS Fixer CI no longer rewrites auto-generated demo config on every run.

## [1.0.6] - 2026-07-13

### Changed

- `.gitignore`: ignore `.cursor/sandbox.json` (machine-specific Cursor sandbox; REQ-IDE-005).
- Dev: `friendsofphp/php-cs-fixer` 3.95.13, `rector/rector` 2.5.6 (`composer.lock`).
- Demo `symfony7` and `symfony8`: `composer.lock` synced after `make update-deps` (path bundle ref to v1.0.5).
- Demo `symfony7`/`symfony8`: `config/reference.php` — restore `declare(strict_types=1);` (PHP CS Fixer CI).

## [1.0.5] - 2026-07-08

### Changed

- Demo `symfony7` and `symfony8`: `composer.lock` synced after `make update-deps` (path bundle ref, `nowo-tech/twig-inspector-bundle` v1.0.34 in demo dev deps).
- Demo `symfony7`/`symfony8`: regenerated `config/reference.php` (Symfony config schema, including `nowo_uptime_monitor.checks.block_private_urls`).

## [1.0.4] - 2026-07-08

### Added

- GitHub Spec Kit integration: `.specify/`, Cursor Agent skills (`.cursor/skills/speckit-*`), and baseline specs under `specs/001-baseline/` (`spec.md`, `code-inventory.md` covering 100% of `src/`).
- [`docs/SPEC-KIT.md`](SPEC-KIT.md) — install, init, structure, and maintainer workflow for Spec Kit.

### Changed

- [`docs/SPEC-DRIVEN-DEVELOPMENT.md`](SPEC-DRIVEN-DEVELOPMENT.md): three-layer model (Spec Kit baseline, product behavior, `REQ-*` traceability) and contributor workflow.
- README: link to GitHub Spec Kit manual in Documentation.
- Dev: `friendsofphp/php-cs-fixer` 3.95.12 (`composer.lock`).

## [1.0.3] - 2026-07-06

### Changed

- Demo `symfony7` and `symfony8`: `composer.lock` synced after `make update-deps` (follow-up to the Makefile fixes in 1.0.2).

## [1.0.2] - 2026-07-06

### Fixed

- `demo/Makefile`: fixed `Makefile.demo-aggregate-update-deps.mk` include (unterminated `abspath` call); `make update-deps` no longer fails at the demo aggregator.
- Demo `symfony7` and `symfony8` Makefiles: set `COMPOSE` and `SERVICE_PHP` so per-demo `make update-deps` runs reliably.

### Changed

- Frontend dev toolchain (Dependabot): Vite 8.1.3, TypeScript 6.0.3, happy-dom 20.10.6, sass 1.101.0, `@types/node` 26.1.0.
- Dev: PHPUnit 11.5.56, Rector 2.5.4.
- CI: `softprops/action-gh-release` v3.

## [1.0.1] - 2026-07-06

### Fixed

- Unit tests aligned with current service and controller APIs (`MonitorUrlSsrfGuard`, `MonitorRetryService`, `DashboardViewBuilder`, `TenantRepository` in retention services, and related constructors).
- CI Symfony **8.1** matrix: dependency resolution when `doctrine/doctrine-bundle` 2.x conflicted with Symfony 8 components.

### Changed

- Dev dependency: `symfony/mercure` bumped to `^0.7.2` (Symfony 8 compatible).
- CI: pins all bundle Symfony components to the matrix version; uses `doctrine/doctrine-bundle` ^3.2 on Symfony 8.x and ^2.18 on Symfony 7.x.
- CI: Dependabot bumps (`actions/cache` v6, `codecov/codecov-action` v7).

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

[Unreleased]: https://github.com/nowo-tech/UptimeMonitorBundle/compare/v1.6.1...HEAD
[1.2.0]: https://github.com/nowo-tech/UptimeMonitorBundle/releases/tag/v1.2.0
[1.1.1]: https://github.com/nowo-tech/UptimeMonitorBundle/releases/tag/v1.1.1
[1.1.0]: https://github.com/nowo-tech/UptimeMonitorBundle/releases/tag/v1.1.0
[1.0.11]: https://github.com/nowo-tech/UptimeMonitorBundle/releases/tag/v1.0.11
[1.0.10]: https://github.com/nowo-tech/UptimeMonitorBundle/releases/tag/v1.0.10
[1.0.9]: https://github.com/nowo-tech/UptimeMonitorBundle/releases/tag/v1.0.9
[1.0.8]: https://github.com/nowo-tech/UptimeMonitorBundle/releases/tag/v1.0.8
[1.0.7]: https://github.com/nowo-tech/UptimeMonitorBundle/releases/tag/v1.0.7
[1.0.6]: https://github.com/nowo-tech/UptimeMonitorBundle/releases/tag/v1.0.6
[1.0.5]: https://github.com/nowo-tech/UptimeMonitorBundle/releases/tag/v1.0.5
[1.0.4]: https://github.com/nowo-tech/UptimeMonitorBundle/releases/tag/v1.0.4
[1.0.3]: https://github.com/nowo-tech/UptimeMonitorBundle/releases/tag/v1.0.3
[1.0.2]: https://github.com/nowo-tech/UptimeMonitorBundle/releases/tag/v1.0.2
[1.0.1]: https://github.com/nowo-tech/UptimeMonitorBundle/releases/tag/v1.0.1
[1.0.0]: https://github.com/nowo-tech/UptimeMonitorBundle/releases/tag/v1.0.0
