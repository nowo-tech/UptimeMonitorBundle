# Monitor configuration (Uptime Kuma parity)

HTTP/HTTPS monitors support the same core options as an Uptime Kuma **HTTP(s)** monitor edit form. Values are stored in the `uptime_monitor.config` JSON column (and related entity fields).

Create or edit monitors at:

```
/uptime/{tenantSlug}/monitors/new
/uptime/{tenantSlug}/monitors/{id}/edit
```

## Entity fields (not in JSON)

| Field | Kuma label | Default | Description |
|-------|------------|---------|-------------|
| `name` | Friendly name | — | Display name |
| `intervalSeconds` | Heartbeat interval | `60` | Seconds between checks (minimum 30) |
| `config.retries` | Retries | `5` | Failed heartbeats before status becomes **Down** |
| `config.retry_interval_seconds` | Heartbeat retry interval | `60` | Delay before retry attempts while in retry state |
| `paused` | — | `false` | Skip scheduling |
| `parent` | Monitor group | — | Group monitor (project folder) |

## General (all HTTP/HTTPS monitors)

| Config key | Form field | Example | Description |
|------------|------------|---------|-------------|
| `url` | URL | `https://example.com/app.js` | Request target |
| `method` | Method | `GET` | `GET`, `HEAD`, `POST` |
| `request_timeout_seconds` | Request timeout | `48` | HttpClient timeout (seconds) |
| `resend_notification_after_down` | Resend if down | `0` | `0` = alert only on transition; `N` = repeat alert every N consecutive down checks |
| `description` | Description | — | Optional notes (stored in config) |

## Advanced

| Config key | Form field | Default | Description |
|------------|------------|---------|-------------|
| `expected_status_codes` | Accepted status codes | `200-299` | Comma-separated list and ranges (`200-299`, `301`) |
| `max_redirects` | Max redirects | `10` | Passed to Symfony HttpClient |
| `ignore_tls` / `verify_ssl` | Ignore TLS/SSL errors | `false` | Disables peer verification when enabled |
| `upside_down` | Upside down mode | `false` | **Up** when unreachable, **Down** when reachable |
| `check_cert_expiry` | Certificate expiry | `false` | HTTPS: **Degraded** if cert expires in &lt; 14 days |
| `keyword` | Keyword in body | — | Response body must contain this string |
| `proxy` | Proxy URL | — | e.g. `http://proxy:8080` |
| `tags` | Tags | — | Array of strings (comma-separated in form) |

## HTTP options

| Config key | Form field | Description |
|------------|------------|-------------|
| `body_encoding` | Body encoding | `json`, `xml`, or `none` (sets `Content-Type` when body is set) |
| `body` | Request body | Raw body for POST/PUT, etc. |
| `headers` | Headers | Map of `Name: value` lines |
| `auth_method` | Authentication | `none` or `basic` |
| `auth_username` | Username | HTTP Basic |
| `auth_password` | Password | HTTP Basic (preserved on edit if left blank) |

## Runtime behaviour

### Status code ranges

`200-299` is parsed into individual codes (200, 201, … 299). See `StatusCodeMatcher`.

### Retries before down

With `retries = 5`, the monitor stays at the previous status for the first 5 consecutive failures; the 6th failure marks it **Down**. Between retries, `next_check_at` uses `retry_interval_seconds` instead of the normal heartbeat interval.

Internal counter: `config.failure_streak` (managed automatically).

### Upside down

Inverts the check result before updating status and incidents.

### Notifications

On status change, notifications respect global `notifications.enabled` and `cooldown_seconds`.

While remaining **Down**, if `resend_notification_after_down` is `N > 0`, an additional alert is sent every N failed checks (counter: `config.down_notify_streak`).

## Example config (CDN asset monitor)

Equivalent to a typical Uptime Kuma production monitor:

```json
{
  "url": "https://widget-dev.cdn.example.com/widget.v3.js",
  "method": "GET",
  "expected_status_codes": "<parsed from 200-299 in the UI>",
  "request_timeout_seconds": 48,
  "max_redirects": 10,
  "retries": 5,
  "retry_interval_seconds": 60,
  "resend_notification_after_down": 0
}
```

In the UI, set **Accepted status codes** to `200-299`; the bundle expands ranges when saving.

## Programmatic access

```php
use Nowo\UptimeMonitorBundle\Form\Model\MonitorFormData;
use Nowo\UptimeMonitorBundle\Service\MonitorFactory;
use Nowo\UptimeMonitorBundle\Monitor\MonitorSettings;

$data = new MonitorFormData();
$data->name = 'CDN widget';
$data->url = 'https://cdn.example/widget.js';
$data->expectedStatusCodes = '200-299';
$data->requestTimeoutSeconds = 48;
$data->retries = 5;

$monitor = $monitorFactory->createFromFormData($tenant, $data);

// Or read config
$timeout = MonitorSettings::from($monitor)->getRequestTimeoutSeconds();
```

## Other monitor types

TCP, DNS, SSL, Ping, and Group monitors use the same form with type-specific sections. See [CHECK-TYPES.md](CHECK-TYPES.md).
