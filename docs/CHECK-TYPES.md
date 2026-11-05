# Check types

| Type | Runner | Target / config |
|------|--------|-----------------|
| `http` / `https` | `HttpCheckRunner` | `url`, `method`, `expected_status_codes`, timeout, redirects, headers, body, auth, proxy, keyword, TLS options — see [MONITOR-CONFIGURATION.md](MONITOR-CONFIGURATION.md) |
| `tcp` | `TcpCheckRunner` | `host`, `port` |
| `dns` | `DnsCheckRunner` | `hostname`, `record_type` (A/AAAA/CNAME/MX/TXT), optional `expected_value` |
| `ssl` | `SslCheckRunner` | `host`, `port`, `days_before_expiry` (degraded if below threshold), optional `expected_spki_pins` (see below) |
| `ping` | `PingCheckRunner` | `host`, optional `timeout` (seconds) |
| `push` | `PushCheckRunner` | no network call: the server pushes its health to `POST <push.path>/{key}`; `push_grace_seconds`, `push_signed`, `push_thresholds` — see [PUSH-MONITORS.md](PUSH-MONITORS.md) |

**DNS notes:** with `checks.block_private_urls: true` (default), a private IP literal or `localhost`/metadata hostname is rejected before the lookup, and an answer containing a private/local address (A/AAAA) or a blocked CNAME/MX target is reported Down as blocked, without storing the records. Set `block_private_urls: false` to monitor internal zones.

**SSL SPKI pinning:** `expected_spki_pins` is an optional list (at most 20) of `sha256/<base64>` hashes of a
certificate's DER SubjectPublicKeyInfo — the HPKP / `pins.json` format. When set, the check also captures the served
chain and is **down** with `Served chain contains none of the expected SPKI pins` unless the leaf, an intermediate or
the root (when the server sends it) has one of those hashes; the result metadata lists `served_spki_pins` so a new
key is easy to spot. A config whose pins are all malformed is **down** without connecting (fail closed). The expiry
and verification checks are unchanged, and pins are public data, not secrets. In the form, enter one pin per line
(`#` comments allowed) or paste a JSON list (`["sha256/…"]` or `{"pins": ["sha256/…"]}`). Compute a pin with
`openssl x509 -in cert.pem -pubkey -noout | openssl pkey -pubin -outform der | openssl dgst -sha256 -binary | base64`.

**Ping notes:** requires the system `ping` binary (Linux/macOS/BSD). Docker containers often need `CAP_NET_RAW` and `iputils-ping` (or equivalent). Unsupported OS families return `unknown`.

**Latency floor:** optional per-monitor `min_latency_ms` in `config` overrides the bundle default `checks.min_latency_ms` (see [CONFIGURATION.md](CONFIGURATION.md)).
