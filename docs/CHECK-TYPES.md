# Check types

| Type | Runner | Target / config |
|------|--------|-----------------|
| `http` / `https` | `HttpCheckRunner` | `url`, `method`, `expected_status_codes`, timeout, redirects, headers, body, auth, proxy, keyword, TLS options — see [MONITOR-CONFIGURATION.md](MONITOR-CONFIGURATION.md) |
| `tcp` | `TcpCheckRunner` | `host`, `port` |
| `dns` | `DnsCheckRunner` | `hostname`, `record_type` (A/AAAA/CNAME/MX/TXT), optional `expected_value` |
| `ssl` | `SslCheckRunner` | `host`, `port`, `days_before_expiry` (degraded if below threshold) |
| `ping` | `PingCheckRunner` | `host`, optional `timeout` (seconds) |

**DNS notes:** with `checks.block_private_urls: true` (default), a private IP literal or `localhost`/metadata hostname is rejected before the lookup, and an answer containing a private/local address (A/AAAA) or a blocked CNAME/MX target is reported Down as blocked, without storing the records. Set `block_private_urls: false` to monitor internal zones.

**Ping notes:** requires the system `ping` binary (Linux/macOS/BSD). Docker containers often need `CAP_NET_RAW` and `iputils-ping` (or equivalent). Unsupported OS families return `unknown`.

**Latency floor:** optional per-monitor `min_latency_ms` in `config` overrides the bundle default `checks.min_latency_ms` (see [CONFIGURATION.md](CONFIGURATION.md)).
