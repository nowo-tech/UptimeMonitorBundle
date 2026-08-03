# Security Policy

## Table of contents

- [Supported Versions](#supported-versions)
- [Reporting a Vulnerability](#reporting-a-vulnerability)
- [Scope and attack surface](#scope-and-attack-surface)
- [Threat model and mitigations](#threat-model-and-mitigations)
- [Dependencies and updates](#dependencies-and-updates)
- [Release security checklist (12.4.1)](#release-security-checklist-1241)

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

- **SSRF / outbound requests**: HTTP/HTTPS monitors use `MonitorUrlSsrfGuard` when `checks.block_private_urls` is `true` (default). Restrict who can create monitors; default UI roles require **`ROLE_ADMIN`**.
- **Stored data**: Check results may contain response snippets and DNS/SSL metadata. Protect database access and backups.
- **Public status page**: Exposes monitor names and last status only (paused monitors hidden). Disable via `status_page.enabled: false` if needed.
- **ICMP ping**: Requires OS `ping` binary; hosts are validated and private/local targets are blocked under the same SSRF guard as HTTP when `block_private_urls` is enabled.
- **Webhooks / Slack**: URLs and tokens belong in environment configuration, not in git.
- **CSRF**: Session-authenticated mutations use Symfony CSRF tokens in Twig forms (`_token`), including monitor delete/pause, tag delete, history purge/clear-stats, and backup import (`backup-import`). Invalid tokens fail closed (no side effects).
- **Access control (REQ-UI-002)**: Admin UI under `dashboard.path` (default `/uptime`) is private by default. Canonical keys:
  - `security.access_roles` (default `[ROLE_ADMIN]`) — general gate
  - `security.access_checker` — optional custom `UptimeMonitorAccessCheckerInterface`
  - `security.allow_unauthenticated` (default `false`) — **demo/dev only**; never enable in production
  - Area roles: `dashboard_roles` / `manage_roles` / `settings_roles` (defaults `ROLE_ADMIN`)
  
  Enforcement: `UptimeMonitorAccessSubscriber` calls the access checker before admin controllers run. The public status page (`/status`) is excluded.

  Host `access_control` example:

  ```yaml
  security:
      access_control:
          - { path: ^/uptime, roles: ROLE_ADMIN }
  ```

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
| **Outbound checks** | Document SSRF risk for HTTP monitors. |

Record confirmation in the release PR or tag notes.
