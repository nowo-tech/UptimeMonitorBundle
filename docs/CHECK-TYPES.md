# Check types

| Type | Runner | Target / config |
|------|--------|-----------------|
| `http` / `https` | `HttpCheckRunner` | `url`, `method`, `expected_status_codes`, timeout, redirects, headers, body, auth, proxy, keyword, TLS options — see [MONITOR-CONFIGURATION.md](MONITOR-CONFIGURATION.md) |
| `tcp` | `TcpCheckRunner` | `host`, `port` |
| `dns` | `DnsCheckRunner` | `hostname`, `record_type` (A/AAAA/CNAME/MX/TXT), optional `expected_value` |
| `ssl` | `SslCheckRunner` | `host`, `port`, `days_before_expiry` (degraded if below threshold) |
| `ping` | `PingCheckRunner` | `host`, optional `timeout` (seconds) |

**Ping notes:** requires the system `ping` binary (Linux/macOS/BSD). Docker containers often need `CAP_NET_RAW` and `iputils-ping` (or equivalent). Unsupported OS families return `unknown`.

**Latency floor:** optional per-monitor `min_latency_ms` in `config` overrides the bundle default `checks.min_latency_ms` (see [CONFIGURATION.md](CONFIGURATION.md)).
