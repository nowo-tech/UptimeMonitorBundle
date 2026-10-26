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
- Operator dashboard and CRUD for tenants/monitors (`/uptime/...`)
- Public read-only status page (`/status/...`)
- JSON APIs under `/api/uptime/...`
- Optional notifications (email, webhook, Slack)
- Doctrine persistence for check results and aggregates

## Threat model and mitigations

- **SSRF / outbound requests**: HTTP/HTTPS, TCP, SSL and ping monitors use `MonitorUrlSsrfGuard` when `checks.block_private_urls` is `true` (default). Blocked ranges are `IpUtils::PRIVATE_SUBNETS` (loopback, RFC1918, link-local, `0.0.0.0/8`, `100.64.0.0/10`, `fc00::/7`, IPv4-mapped IPv6, …) plus multicast/broadcast; every A/AAAA record is checked. HTTP checks run through `NoPrivateNetworkHttpClient`, so redirects and the connected IP are re-validated (DNS rebinding), and a private per-monitor `proxy` is rejected. TCP/SSL/ping connect to the validated, pinned IP. DNS monitors (system resolver, no connection) validate the hostname (private IP literals, `localhost`, metadata hosts) before the lookup and report an answer with any private/local A/AAAA address or blocked CNAME/MX target as blocked, without exposing the resolved records. Restrict who can create monitors; default UI roles require **`ROLE_ADMIN`**. Regression coverage: `tests/Unit/Security/MonitorUrlSsrfGuardTest.php`, `HttpCheckRunnerTest`, `PingCheckRunnerTest`, `DnsCheckRunnerTest`.
- **Stored data**: Check results may contain response snippets and DNS/SSL metadata. Protect database access and backups.
- **Public status page**: Exposes monitor names and last status only (paused monitors hidden). Disable via `status_page.enabled: false` if needed.
- **ICMP ping**: Requires OS `ping` binary; hosts are validated and private/local targets are blocked under the same SSRF guard as HTTP when `block_private_urls` is enabled.
- **Webhooks / Slack**: URLs and tokens belong in environment configuration, not in git. Delivery errors are reported with the endpoint origin only (`scheme://host[:port]`), never the path/query that carries the token.
- **Resource limits / DoS (REQ-RUNTIME-001)**: every outbound check and notification has an explicit deadline (HTTP `timeout` + `max_duration`, TCP/SSL connect timeout, ping `-W`/`-w` clamped to 30 s, webhook `notifications.http_timeout`), and checks run only in CLI/worker processes, never in a web request. See [CONFIGURATION.md → Timeouts](CONFIGURATION.md#timeouts-frankenphp-safe-req-runtime-001). Detail rows are purged in keyset batches (`retention.purge_batch_size`).
- **CSRF**: Session-authenticated mutations use Symfony CSRF tokens in Twig forms (`_token`), including monitor delete/pause, tag delete, history purge/clear-stats, and backup import (`backup-import`). Invalid tokens fail closed (no side effects).
- **Access control (REQ-UI-002)**: Admin UI under `dashboard.path` (default `/uptime`) is private by default. Canonical keys:
  - `security.access_roles` (default `[ROLE_ADMIN]`) — general gate
  - `security.access_checker` — optional custom `UptimeMonitorAccessCheckerInterface`
  - `security.allow_unauthenticated` (default `false`) — **demo/dev only**; never enable in production
  - Area roles: `dashboard_roles` / `manage_roles` / `settings_roles` (defaults `ROLE_ADMIN`). Tenant creation and any unmapped POST/PUT/PATCH/DELETE action require `settings_roles`.
  
  Enforcement: `UptimeMonitorAccessSubscriber` calls the access checker before admin controllers run. The public status page (`/status`) is excluded.

  Host `access_control` example:

  ```yaml
  security:
      access_control:
          - { path: ^/uptime, roles: ROLE_ADMIN }
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
| **Permissions** | Document `security.access_roles`, area `*_roles`, and firewall `access_control` for `/uptime`. Confirm `allow_unauthenticated: false` in production. |
| **Outbound checks** | Document SSRF risk for HTTP monitors; `checks.block_private_urls` stays `true` by default. |
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
