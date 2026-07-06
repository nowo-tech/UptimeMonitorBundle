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

- **SSRF / outbound requests**: Monitors call user-configured URLs and hosts. Restrict who can create monitors; use `dashboard.roles` and Symfony Security in production.
- **Stored data**: Check results may contain response snippets and DNS/SSL metadata. Protect database access and backups.
- **Public status page**: Exposes monitor names and last status only (paused monitors hidden). Disable via `status_page.enabled: false` if needed.
- **ICMP ping**: Requires OS `ping` binary; validate monitor creation permissions to avoid abuse from the app container.
- **Webhooks / Slack**: URLs and tokens belong in environment configuration, not in git.
- **CSRF**: Monitor delete/pause actions use Symfony CSRF tokens in Twig forms.

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
| **Permissions** | Document `dashboard.roles` and firewall for production. |
| **Outbound checks** | Document SSRF risk for HTTP monitors. |

Record confirmation in the release PR or tag notes.
