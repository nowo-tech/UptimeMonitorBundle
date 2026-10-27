# Security Policy

## Table of contents

- [Supported Versions](#supported-versions)
- [Reporting a Vulnerability](#reporting-a-vulnerability)
- [Scope and attack surface](#scope-and-attack-surface)
- [Threat model and mitigations](#threat-model-and-mitigations)
- [Logging policy](#logging-policy)
- [Dependencies and updates](#dependencies-and-updates)
- [Release security checklist (12.4.1)](#release-security-checklist-1241)
- [AI security audit](#ai-security-audit)

## Supported Versions

| Version | Supported          |
| ------- | ------------------ |
| 1.x     | :white_check_mark: |

## Reporting a Vulnerability

Please report vulnerabilities privately by email: **hectorfranco@nowo.tech**.

Do not open public issues for security-sensitive reports.

## Scope and attack surface

This bundle provides:

- Scheduled synthetic checks (HTTP, TCP, DNS, SSL, ICMP ping) against configured targets
- Operator dashboard and CRUD for tenants/monitors (`web_ui.path_prefix`, default `/uptime/...`, plus any
  `web_ui.tenant_paths` copies of the tenant pages)
- Public read-only status page (`/status/...`, per-tenant opt-in)
- JSON APIs under `/api/uptime/...` (a separate prefix: protect it in the host firewall too)
- Backup export / import of monitor definitions (Settings → Backup)
- Optional notifications (email, webhook, Slack)
- Doctrine persistence for check results and aggregates

## Threat model and mitigations

- **SSRF / outbound requests**: HTTP/HTTPS, TCP, SSL and ping monitors use `MonitorUrlSsrfGuard` when `checks.block_private_urls` is `true` (default). Blocked ranges are `IpUtils::PRIVATE_SUBNETS` (loopback, RFC1918, link-local, `0.0.0.0/8`, `100.64.0.0/10`, `fc00::/7`, IPv4-mapped IPv6, …) plus multicast/broadcast; every A/AAAA record is checked. HTTP checks run through `NoPrivateNetworkHttpClient`, so redirects and the connected IP are re-validated (DNS rebinding), and a private per-monitor `proxy` is rejected. TCP/SSL/ping connect to the validated, pinned IP. DNS monitors (system resolver, no connection) validate the hostname (private IP literals, `localhost`, metadata hosts) before the lookup and report an answer with any private/local A/AAAA address or blocked CNAME/MX target as blocked, without exposing the resolved records. Restrict who can create monitors; default UI roles require **`ROLE_ADMIN`**. Regression coverage: `tests/Unit/Security/MonitorUrlSsrfGuardTest.php`, `HttpCheckRunnerTest`, `PingCheckRunnerTest`, `DnsCheckRunnerTest`.
- **SSRF hardening details**: the per-monitor proxy is resolved once, validated and pinned to that IP; the HTTPS
  certificate-expiry probe reads the certificate chain of the validated HTTP connection (no second lookup).
- **Shared scan / DoS (M1)**: every check is capped by `checks.max_timeout_seconds` (60 s), `checks.max_redirects` (20)
  and `checks.max_body_bytes` (1 MiB, body read only for a keyword); a throwing check is stored as `unknown` and
  rescheduled, a failing monitor execution is logged and skipped, and `checks.max_scan_seconds` bounds one scan. Every
  outbound call has an explicit deadline and checks run only in CLI/worker processes — see
  [CONFIGURATION.md → Check limits](CONFIGURATION.md#check-limits-sec-004) and
  [Timeouts](CONFIGURATION.md#timeouts-frankenphp-safe-req-runtime-001). Detail rows are purged in keyset batches.
- **Stored data**: check results may contain response snippets and DNS/SSL metadata; monitor configs hold the secrets
  monitor managers enter (basic-auth password, custom headers such as `Authorization`, proxy and URL credentials).
  Protect database access and database backups.
- **Credentials in targets**: URL userinfo (`https://user:pass@host`) is masked as `***@` in the dashboard, the
  monitor page, alert e-mails and webhook payloads (`SecretRedactor`); only the monitor edit form shows the real value.
- **Bundle backups** (Settings → Backup): exports redact URL / proxy credentials, the basic-auth password and every
  custom header value — the file is safe to hand around but secrets must be re-entered after an import. Imports are
  capped (2 MB, 1000 monitors), rows that fail the monitor constraints are skipped, and errors are shown as a generic
  message (exception class logged).
- **Public status page**: exposes monitor names and last status only (paused monitors hidden). Public per tenant when
  *Settings → General → Public status page* says so, or by `status_page.default_public` (default `true`, 1.x behaviour;
  set `false` for opt-in). Unknown, disabled and private tenants answer the same 404. Disable all of them with
  `status_page.enabled: false`.
- **Disabled tenants**: monitors of a disabled tenant (`TenantProvisioner::setEnabled(false)`) are not checked.
- **ICMP ping**: requires the OS `ping` binary; hosts are validated and private/local targets are blocked under the
  same SSRF guard as HTTP when `block_private_urls` is enabled.
- **Webhooks / Slack**: URLs and tokens belong in environment configuration, not in git. Delivery errors are reported
  with the endpoint origin only (`scheme://host[:port]`), never the path/query that carries the token.
- **CSRF (REQ-SEC-005)**: every admin mutation is a Symfony form with a CSRF token — monitor create/edit (modal form),
  tenant creation, settings screens, tag delete, history purge / clear statistics (`CsrfActionFormType`) and backup
  import (`backup-import`). Invalid tokens fail closed (no side effects). `CsrfProtectionPass` refuses to compile the
  container when the admin UI is enabled without form CSRF protection or a token manager, so a host cannot turn the
  checks off silently.
- **Output escaping**: Twig auto-escaping for every page; the dashboard script escapes every API value it renders
  (monitor names, messages, statuses, times).
- **Access control (REQ-UI-002 / REQ-UI-003)**: the admin UI is private by default. Canonical keys:
  - `security.access_roles` (default `[ROLE_ADMIN]`) — general gate (at least one role).
  - `security.roles.<action>` — `dashboard` (dashboard, read and polling APIs), `manage` (monitor mutations),
    `settings` (tenant settings, tenant creation and any unmapped POST/PUT/PATCH/DELETE action). Defaults
    `[ROLE_ADMIN]`; the former `dashboard_roles` / `manage_roles` / `settings_roles` keys are deprecated aliases.
  - `security.access_checker` — optional custom `UptimeMonitorAccessCheckerInterface`. A checker implementing
    `TenantAwareUptimeMonitorAccessCheckerInterface` decides every request that carries a `{tenantSlug}` per tenant
    (`canAccessTenant`, `canManageTenantMonitors`, `canManageTenantSettings`) and filters the `/tenants` list.
  - `security.allow_unauthenticated` (default `false`) — **demo/dev only**; never enable in production.

  Enforcement: `UptimeMonitorAccessSubscriber` calls the access checker before every admin controller and API
  controller runs — including the `web_ui.tenant_paths` copies of the tenant pages. The public status page
  (`status_page.path`) is excluded. Keep a host firewall rule for **every** prefix — the admin prefix, each
  `web_ui.tenant_paths` path and `/api/uptime`:

  ```yaml
  security:
      access_control:
          - { path: ^/uptime, roles: ROLE_ADMIN }
          - { path: ^/api/uptime, roles: ROLE_ADMIN }
          # one line per web_ui.tenant_paths entry whose path is outside ^/uptime
  ```

## Logging policy

REQ-OBS-001. The bundle logs through the injected PSR-3 `LoggerInterface` (host Monolog channel `app` unless you wire
another) — never `dump()`, `var_dump()` or `error_log()` in `src/`. Context is structured (`bundle`, `channel`,
`monitor_id`, `transition`, `message`, `error`), not interpolated user data.

| Event | Level | Context |
|-------|-------|---------|
| Notification about to be sent (`NotificationService`) | `debug` | `channel`, `monitor_id`, `transition` |
| Notification sent | `info` | `channel`, `monitor_id`, `transition` |
| Notification failed (webhook / Slack / e-mail) | `error` | same + `error`: the redacted `NotificationDeliveryException` message (channel, endpoint origin, HTTP status or transport error class) — for any other exception only its **class** |
| Due-check scan (`RunDueChecksMessageHandler`) done | `debug` | `message` class, `executed` count |
| Due-check scan failed (rethrown for Messenger retry) | `error` | `message` class, `error`: exception **class** |
| One monitor's execution failed (`DueChecksRunner`, scan continues) | `error` | `monitor_id`, `error`: exception **class** |
| Scan budget spent (`checks.max_scan_seconds`) | `warning` | `seconds`, `executed` |
| Data clear started / done (`nowo:uptime:clear-data`, Settings → History → Clear statistics) | `info` | `tenant` (slug or `*`), then `checks`, `aggregates`, `incidents`, `monitors_reset` |
| Detail purge started / done (`nowo:uptime:purge-detail`, Settings → History → Purge) | `info` | `tenant`, `days`, then `deleted` |
| Backup export | `info` | `tenant`, `count` |
| Backup import started / done | `info` | `tenant`, `mode`, `rows`, then `imported`, `skipped`, `overwritten`, `invalid` |
| Clear / purge / import failed | `error` (`warning` for a rejected import file) | `tenant`, `error`: exception **class** |

**Never logged:** webhook / Slack URLs (their path or query is the secret), HTTP basic-auth credentials and custom
headers of monitors, proxy URLs, alert e-mail recipients, alert bodies, monitor targets, database DSNs, Mercure JWTs,
session ids or request payloads. Check failures are *data*, not logs: the transport error of a failing target is
stored on the check result (visible to dashboard roles only) and is not written to the log.

When you add logging (custom channels, decorators), follow the same rules: structured ids, redact URLs to their
origin, prefer exception classes over raw exception messages from HTTP / mailer / DBAL layers.

## Dependencies and updates

- Run `composer audit` regularly.
- Keep Symfony, Doctrine, and dev tooling updated.
- Review `pnpm` / Vite dependency advisories for dashboard assets.

## Release security checklist (12.4.1)

Before tagging a release, confirm:

| Item | Notes |
|------|--------|
| **SECURITY.md** | Current and linked from README. |
| **`.gitignore` and `.env`** | No committed secrets; demos use `.env.example` only. |
| **Recipe / Flex** | Default recipe ships no production secrets. |
| **Input / output** | Monitor targets validated where possible; Twig escapes output. |
| **Dependencies** | `composer audit` run; issues triaged. |
| **Permissions** | Document `security.access_roles`, `security.roles.<action>`, tenant-aware checkers and firewall `access_control` for the admin prefix, `web_ui.tenant_paths` and `/api/uptime`. Confirm `allow_unauthenticated: false` in production. |
| **Outbound checks** | Document SSRF risk for HTTP monitors; `checks.block_private_urls` stays `true` by default; `checks.max_*` caps keep their defaults. |
| **CSRF** | `CsrfProtectionPass` still fails closed; every admin mutation is a form with a CSRF token. |
| **Backups** | Exports still redact secrets; import caps (2 MB / 1000 rows) and row validation unchanged. |
| **No-secret logs** | [Logging policy](#logging-policy) still holds: no webhook URLs, credentials, recipients or payloads in log calls (`grep -rn 'logger' src/`). |
| **Cryptography** | No custom cryptography; TLS verification stays on for SSL checks (only the per-monitor "ignore TLS errors" option disables it, for that HTTP check). |
| **Limits / DoS** | Every outbound call keeps an explicit deadline ([Timeouts](CONFIGURATION.md#timeouts-frankenphp-safe-req-runtime-001)); purge stays batched. |
| **AI security audit (REQ-SEC-004)** | Package **passes** the latest AI security audit: grade **Pass (good)** / risk **Low** (re-audit 2026-09-28), recorded in the Nowo monorepo `BUNDLES_SECURITY_ANALYSIS.md`. Re-run the audit when outbound checks, notifications or access control change, and do not release on a failing grade. |

Record confirmation in the release PR or tag notes.

Recommended commands:

```bash
composer audit
make release-check
```

## AI security audit

| Field | Value |
| ----- | ----- |
| Latest audit | 2026-09-28 (re-audit) |
| Grade | **Pass (good)** |
| Risk | Low |
| Record | `BUNDLES_SECURITY_ANALYSIS.md` (Nowo bundles monorepo), REQ-SEC-004 table |
| Residual | Host must keep the `/uptime` admin area behind `ROLE_ADMIN` / firewall; `block_private_urls: false` re-opens SSRF to private networks by design |
