# Push monitors — server health (disk, CPU, memory, load)

Since **1.14.0**. A push monitor is watched from inside the server: an agent on the server POSTs its health to the
bundle every interval. The bundle judges the numbers against the monitor's thresholds, and treats a missing push as
*down*. No port is opened on the server and nothing on it is exposed.

## Table of contents

- [How it works](#how-it-works)
- [Security](#security)
- [Set up a server](#set-up-a-server)
- [The push API](#the-push-api)
- [Thresholds and status](#thresholds-and-status)
- [Configuration](#configuration)
- [The monitor page](#the-monitor-page)

## How it works

1. Create a monitor of type **Server health (push)** (interval, grace, thresholds). It gets an opaque URL key.
2. On its page, **Generate token**: the page shows the URL, the token and, for a signing monitor, the signing secret,
   **once**.
3. Install the agent on the server ([below](#set-up-a-server)); each run sends one push.
4. On each interval the scheduler judges the last push (`PushCheckRunner`). It stores the result with the metrics,
   and the usual events, incidents and notifications follow. Hosts listening to status events, such as Beacon issues,
   need no change.

One installation serves every tenant: there is **one ingest route**, and the key names the monitor. A tenant can have
as many push monitors (one per server) as its limits allow. Push monitors count against `limits.max_monitors` and the
shortest interval like any other monitor.

## Security

- **Push, never pull.** The server only makes outbound HTTPS requests; no metrics endpoint, no open port.
- **The tenant comes from the monitor.** It is never read from the URL, a header or the body, so a token cannot write
  into another tenant.
- **Opaque URL key.** The key is 128 random bits, not the numeric id or the tenant slug, so monitors cannot be
  enumerated and the URL names no one.
- **Write-only bearer token.** The token can only feed its monitor. Only its SHA-256 is stored, the comparison is
  constant time, and it is shown once. **Rotate** issues a new one (the old one stops at once) and **Revoke** removes
  it. The token travels in `Authorization: Bearer`, never in the URL (URLs end up in proxy and access logs).
- **No existence oracle.** An unknown key, a wrong token, a revoked token and a bad signature all get the same
  `401 {"error":"unauthorized"}`. A paused monitor or disabled tenant gets 403 only after authentication.
- **Optional signature (anti-replay).** With *Require signed pushes*, each push also carries `X-Uptime-Timestamp` and
  `X-Uptime-Signature: hex(HMAC-SHA256(secret, timestamp + "." + body))`, and the timestamp must be within
  `push.signature_window_seconds`. The secret is never stored: it is derived from `kernel.secret`, the key and the token
  hash. A database dump alone cannot sign, and rotating the token rotates the secret. Changing `kernel.secret`
  invalidates the secrets, so generate the tokens again.
- **Strict input.** The body must be JSON of at most `push.max_body_bytes` (413 above, whatever `Content-Length`
  says). Only known keys are accepted, numbers must be in range, at most 32 disks, and mount points are printable
  text. Anything else gets 422 with a generic list that never echoes the input. Unknown keys are refused, not ignored.
- **Rate limit per monitor.** At most one push per `max(push.min_push_seconds, interval / 2)`; above that, 429 with
  `Retry-After`. Nothing is stored for a refused push. Floods of unauthenticated requests should also be limited at
  the reverse proxy (for example `limit_req` on the `push.path` prefix).
- **The agent** runs as an unprivileged user and only reads `/proc` and `df`. Its config file is parsed, never sourced,
  and nothing the server answers is executed. The token and secret never appear on a command line, so they are hidden
  from `ps`.
- **What is sent:** percentages, load, core count and uptime, plus mount points. No hostnames, IPs, users or process
  names.

## Set up a server

The reference agent is served with the bundle assets: `/bundles/uptimemonitor/agent/uptime-push-agent.sh`, or the
*Download the agent* link next to the token. It needs only POSIX `sh`, `curl`, `awk`, `df` and `sha256sum`.

```bash
sudo useradd --system --no-create-home --shell /usr/sbin/nologin uptime-push
sudo install -m 0755 uptime-push-agent.sh /usr/local/bin/uptime-push-agent
sudo install -m 0640 -o root -g uptime-push /dev/null /etc/uptime-push-agent.conf
sudoedit /etc/uptime-push-agent.conf   # paste the lines shown once on the monitor page
UPTIME_PUSH_DRY_RUN=1 sudo -u uptime-push uptime-push-agent   # prints the JSON it would send
```

`/etc/systemd/system/uptime-push-agent.service`:

```ini
[Unit]
Description=Push this server's health to the uptime monitor
After=network-online.target

[Service]
Type=oneshot
User=uptime-push
ExecStart=/usr/local/bin/uptime-push-agent
NoNewPrivileges=yes
ProtectSystem=strict
ProtectHome=yes
PrivateTmp=yes
PrivateDevices=yes
RestrictAddressFamilies=AF_INET AF_INET6 AF_UNIX
CapabilityBoundingSet=
```

`/etc/systemd/system/uptime-push-agent.timer` (the interval of the monitor; here one minute):

```ini
[Unit]
Description=Run uptime-push-agent every minute

[Timer]
OnBootSec=30s
OnUnitActiveSec=60s
AccuracySec=5s

[Install]
WantedBy=timers.target
```

```bash
sudo systemctl daemon-reload && sudo systemctl enable --now uptime-push-agent.timer
```

`UPTIME_PUSH_MOUNTS=/ /var` in the config limits the disks to those mount points (default: every real filesystem;
tmpfs, devtmpfs, overlay and squashfs are left out).

## The push API

Any client can push; the agent is only one.

```http
POST /uptime-push/0123456789abcdef0123456789abcdef
Authorization: Bearer upm_…
Content-Type: application/json

{"cpu_pct": 12.5, "mem_pct": 61, "swap_pct": 0, "load1": 0.4, "load5": 0.3, "load15": 0.2, "cpu_count": 4,
 "uptime_s": 86400, "disks": [{"mount": "/", "used_pct": 71.2, "inodes_pct": 9}]}
```

| Key | Range |
|---|---|
| `cpu_pct`, `mem_pct`, `swap_pct` | 0 – 100 |
| `load1`, `load5`, `load15` | 0 – 10 000 |
| `cpu_count` | 1 – 4096 |
| `uptime_s` | 0 – 10¹⁰ |
| `disks[]` | at most 32: `mount` (1 – 255 printable characters), `used_pct`, `inodes_pct` (0 – 100) |

Every key is optional. An empty body (or `{}`) is a plain heartbeat: the server is alive, and no threshold is
checked.

| Answer | When |
|---|---|
| `202 {"status":"accepted"}` | stored |
| `401 {"error":"unauthorized"}` | unknown key, wrong / revoked token, missing or bad signature |
| `403 {"error":"monitor_disabled"}` | paused monitor or disabled tenant |
| `413 {"error":"payload_too_large"}` | body above `push.max_body_bytes` |
| `415 {"error":"unsupported_media_type"}` | a non-empty body that is not `application/json` |
| `422 {"error":"invalid_payload","details":[…]}` | schema errors |
| `429 {"error":"too_many_pushes"}` + `Retry-After` | sooner than the monitor allows |
| `404` | `push.enabled: false` |

## Thresholds and status

Each metric has a **warning** and a **critical** limit (empty = off):

| Metric | Warning | Critical |
|---|---|---|
| Disk used (each mount) | 85 % | 95 % |
| Inodes used (each mount) | 85 % | 95 % |
| Memory | 90 % | 97 % |
| CPU | 85 % | 95 % |
| Swap | 50 % | 80 % |
| Load per core (`load5 / cpu_count`) | 1.5 | 3 |

A warning makes the monitor *degraded* and a critical makes it *down*. The worst metric names the message, for example
`Disk / at 96.0 % (critical ≥ 95 %)`. The monitor's **retries** are the pushes in a row over a limit before it counts,
so a CPU spike does not alert. No push within *interval + grace* (grace defaults to one interval) is *down*:
`No push for 190 s (allowed 120 s)`.

## Configuration

```yaml
nowo_uptime_monitor:
    push:
        enabled: true                  # false: the ingest route answers 404
        path: /uptime-push             # one route for every tenant; keep it outside the dashboard path
        max_body_bytes: 8192           # 512 – 65536
        signature_window_seconds: 300  # 30 – 3600
        min_push_seconds: 10           # floor of the per-monitor rate limit
```

The ingest route is public: no session or CSRF, and the token is the credential. A host with a firewall on every URL
must let it through anonymously (Symfony: `access_control: { path: ^/uptime-push/, roles: PUBLIC_ACCESS }`). The
token, rotate and revoke actions are monitor mutations, so they need `canManageMonitors` / `canManageTenantMonitors`.

## The monitor page

A push monitor's page shows a **Server health** view:

- credentials: the URL, whether a token is active, and the last push;
- gauges: CPU, memory, swap, load per core and uptime, coloured by their level;
- a disks table with used and inodes per mount;
- charts of usage (CPU, memory, swap), load per core and each disk over 1 h, 6 h, 24 h or 7 days (`?range=`).

The charts read the stored checks, so they follow `retention.detail_days`.
