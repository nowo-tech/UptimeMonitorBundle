# Feature Specification: Push monitors and tenant status summary

**Feature Branch**: `003-push-monitors`  
**Created**: 2026-11-01 (backfilled from the 1.13.0 and 1.14.0 releases)  
**Status**: Implemented (v1.13.0 status summary, v1.14.0 push monitors)  
**Input**: Pull checks cannot see inside a server (disk, CPU, memory, load) without exposing a metrics endpoint. A push
monitor lets an agent on the server POST its health to the bundle; a missing push counts as *down*. Tenant users also
need the status of their monitors at a glance inside the admin UI, without the public status page.

**Related docs**: [`docs/PUSH-MONITORS.md`](../../docs/PUSH-MONITORS.md), [`docs/SECURITY.md`](../../docs/SECURITY.md#threat-model-and-mitigations),
[`docs/CONFIGURATION.md`](../../docs/CONFIGURATION.md#push-monitors), baseline
[`001-baseline/spec.md`](../001-baseline/spec.md) (FR-CHECK-001, FR-UI-006, FR-UI-007, FR-SEC-001)

---

## User Scenarios & Testing

### US-01 — Watch a server's health without opening a port (Priority: P1)

As a monitor manager, I create a monitor of type **Server health (push)**, generate its token and install the agent
on the server. The monitor goes *degraded* or *down* when a metric crosses its limit, and *down* when no push arrives.

**Acceptance**:

1. The monitor gets an opaque URL key; **Generate token** shows the URL, the token and, for a signing monitor, the
   signing secret once (response `Cache-Control: no-store, private`).
2. A push with the right token is stored (`202`) and judged on the next interval against the monitor's thresholds.
3. No push within *interval + grace* gives *down* with `No push for N s (allowed M s)`.
4. Status events, incidents and notifications follow as for any other monitor.

### US-02 — An attacker on the internet cannot feed or probe a monitor (Priority: P1)

As an operator exposing `push.path`, I rely on the ingest refusing every request that does not carry the monitor's
token, without telling which keys exist.

**Acceptance**:

1. An unknown key, a missing / wrong / revoked token and a missing or bad signature all answer the same
   `401 {"error":"unauthorized"}` with `WWW-Authenticate: Bearer`, at the same cost (constant-time comparison against
   a dummy hash when the key matches nothing).
2. A paused monitor or a disabled tenant answers `403` only after authentication.
3. A body above `push.max_body_bytes` answers `413` whatever `Content-Length` says; a non-JSON body `415`; an invalid
   body `422` with a generic error list that never echoes the input; a push sooner than the monitor allows `429` with
   `Retry-After`. Nothing is stored for a refused push.
4. With `push.enabled: false` the route answers `404`.

### US-03 — Tenant status summary (Priority: P2)

As a tenant user with dashboard access, I open `<web_ui.path_prefix>/{tenantSlug}/summary` and see how many active
monitors are operational, the others by status, each monitor's latest check (linking to its page) and the link to the
public status page when that page is public.

---

## Requirements

- **FR-PUSH-001**: One ingest route for every tenant, `POST <push.path>/{key}` (`nowo_uptime_push`, key
  `[a-f0-9]{32}`), registered only where the host imports the bundle `routes.yaml` (REQ-SF-004 rule 7). It has no
  session, cookie or CSRF: the bearer token is the only credential. The tenant is the monitor's, never read from the
  URL, a header or the body. `push.enabled: false` answers 404. Every answer is JSON with `Cache-Control: no-store`
  and `X-Content-Type-Options: nosniff`. The route is outside `UptimeMonitorAccessSubscriber` (not an admin or API
  controller).
- **FR-PUSH-002**: Credentials (`PushCredentials`): a URL key of 128 random bits (hex); a token `upm_` + 256 random bits
  (base64url) shown once, of which only the SHA-256 is stored and compared with `hash_equals`; the token travels in
  `Authorization: Bearer` (at most 512 characters), never in the URL. **Rotate** (`push_token`) replaces it at once,
  **Revoke** (`push_revoke`) removes it. Both are CSRF-protected monitor mutations (`canManageMonitors` /
  `canManageTenantMonitors`).
- **FR-PUSH-003**: Optional anti-replay signature (monitor config `push_signed`): `X-Uptime-Timestamp` and
  `X-Uptime-Signature = hex(HMAC-SHA256(secret, timestamp + "." + body))`, the timestamp within
  `push.signature_window_seconds` (30 – 3600, default 300). The secret is derived from `kernel.secret`, the key and the
  token hash and never stored, so a database dump alone cannot sign and rotating the token rotates the secret.
- **FR-PUSH-004**: Input limits: body read up to `push.max_body_bytes` + 1 (512 – 65536, default 8192; 413 above);
  strict schema (`PushPayload`): only known keys, numbers in range, at most 32 disks with printable mount points of
  1 – 255 characters; an empty body or `{}` is a heartbeat. Rate limit per monitor: one push per
  `max(push.min_push_seconds, interval / 2)` (429 + `Retry-After`).
- **FR-PUSH-005**: Judgement (`PushCheckRunner`, `PushThresholds`): warning and critical limits per metric (disk and
  inodes per mount, memory, CPU, swap, load per core; empty = off). A warning gives *degraded*, a critical *down*; the
  worst metric names the message. The monitor's retries are the consecutive breaches before it counts. No push within
  interval + grace (`push_grace_seconds`, default one interval) is *down*. The runner makes no network call.
- **FR-PUSH-006**: Server health view on the monitor page (`PushMetricsSeries`, `monitor/_push_health.html.twig`,
  `push-health.ts`): credentials state, gauges of the last sample coloured by level, disks table, and charts over
  1 h / 6 h / 24 h / 7 days (`?range=`, at most 240 points per series) read from the stored checks
  (`retention.detail_days`).
- **FR-PUSH-007**: Reference agent `Resources/public/agent/uptime-push-agent.sh` (POSIX `sh`, `curl`, `awk`, `df`,
  `sha256sum`): runs unprivileged, reads only `/proc` and `df`, parses its config file (never sources it), keeps the
  token and secret off the command line, executes nothing the server answers, and sends no hostnames, IPs, users or
  process names.
- **FR-CFG-004**: `push.enabled` (default `true`), `push.path` (default `/uptime-push`), `push.max_body_bytes`,
  `push.signature_window_seconds`, `push.min_push_seconds` (default 10) — documented in
  [CONFIGURATION](../../docs/CONFIGURATION.md#push-monitors).
- **FR-UI-007** (baseline, detailed here): `SummaryController` (`nowo_uptime_summary`,
  `<web_ui.path_prefix>/{tenantSlug}/summary`) and `StatusSummary` (active monitors, counts per status, overall
  status) behind `UptimeMonitorAccessSubscriber` (dashboard access; `canAccessTenant` with a tenant-aware checker);
  unknown tenant 404. Template `summary/index.html.twig` (page id `summary`).

## Explicit non-goals

- No pull of metrics from the server (no agent port, no metrics endpoint).
- No per-IP rate limit inside the bundle for unauthenticated floods: that belongs to the reverse proxy
  (`limit_req` on the `push.path` prefix).
- No host inventory data (hostnames, IPs, processes) in a push.

## Success Criteria

- **SC-001**: Unit tests cover the ingest answers 202 / 401 (unknown key, wrong, revoked, bad or stale signature) /
  403 / 404 / 413 (declared and actual length) / 415 / 422 / 429, credentials, payload ranges, thresholds, the runner
  and the series: `tests/Unit/Controller/PushControllerTest.php`, `tests/Unit/Controller/MonitorControllerPushTest.php`,
  `tests/Unit/Push/*Test.php`, `tests/Unit/Check/PushCheckRunnerTest.php`, `tests/Unit/Service/MonitorFactoryPushTest.php`,
  `src/Resources/assets/src/push-health.test.ts`.
- **SC-002**: The summary page is covered by `tests/Unit/Controller/SummaryControllerTest.php` and
  `tests/Unit/Service/StatusSummaryTest.php`.
- **SC-003**: Every file of this feature is in [`001-baseline/code-inventory.md`](../001-baseline/code-inventory.md)
  (section *Push monitors*); `make check-spec-inventory` passes.
- **SC-004**: PHP line coverage stays 100% and `make release-check` is green.

## Validation

| Check | Command |
| --- | --- |
| Tests | `make test` / `make test-ts` |
| Inventory | `make check-spec-inventory` |
| Full QA | `make release-check` |
