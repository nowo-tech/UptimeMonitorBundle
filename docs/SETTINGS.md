# Tenant settings (Uptime Kuma parity)

Per-tenant settings mirror the **Settings** screen in [Uptime Kuma](https://github.com/louislam/uptime-kuma). Values are stored as JSON on the `uptime_tenant.settings` column and edited in the Twig UI.

## URL

```
/uptime/{tenantSlug}/settings
```

Sections:

| Route suffix | Uptime Kuma section | Description |
|--------------|---------------------|-------------|
| `/general` | General | Timezone, entry page, base URL, DNS/cache options |
| `/appearance` | Appearance | Language, theme, heartbeat bar style, elapsed time |
| `/notifications` | Notifications | Read-only summary of bundle YAML notification config |
| `/reverse-proxy` | Reverse Proxy | Trust `X-Forwarded-*` headers flag |
| `/tags` | Tags | CRUD for `uptime_tag` records |
| `/history` | Monitor History | Retention override, purge, clear statistics |
| `/backup` | Backup | Export/import monitor definitions (JSON) |
| `/about` | About | Bundle info |

## Schema sync

After upgrading the bundle, apply Doctrine schema updates:

```bash
php bin/console nowo:uptime:sync-schema
```

This adds `uptime_tenant.settings` and the `uptime_tag` table when missing (idempotent: safe if tables already exist).

## General

| Setting key | Form field | Default | Notes |
|-------------|------------|---------|-------|
| `display_timezone` | Display timezone | `auto` | `auto` uses the browser timezone in the UI |
| `server_timezone` | Server timezone | `UTC` | PHP timezone name, e.g. `Europe/Madrid` |
| `search_engine_index` | Allow indexing | `false` | When false, emits `<meta name="robots" content="noindex">` |
| `entry_page` | Entry page | `dashboard` | `dashboard` or `status` (reserved for future redirect) |
| `primary_base_url` | Primary base URL | empty | Public URL for status links and notifications |
| `steam_api_key` | Steam Web API key | empty | Reserved for Steam game server monitors |
| `nscd_enabled` | Enable NSCD | `true` | Stored for parity; not enforced by the bundle yet |
| `http_dns_cache` | HTTP DNS cache | `false` | Stored for parity; not enforced by the bundle yet |
| `chromium_executable` | Chromium path | `auto` | Reserved for browser-based checks |

## Appearance

| Setting key | Form field | Default | Notes |
|-------------|------------|---------|-------|
| `theme` | Theme | `auto` | `light`, `dark`, or `auto` |
| `heartbeat_bar_theme` | Heartbeat bar | `normal` | `normal`, `bottom`, or `none` |
| `elapsed_time` | Elapsed time | `show` | `show`, `show_line`, or `none` |
| `ui_framework` | UI framework | (global YAML) | `default`, `custom`, `bootstrap`, or `tailwind`; see [CONFIGURATION.md](CONFIGURATION.md#ui-framework-bootstrap--tailwind) |

**Interface language** is not stored per tenant; it follows the host app **session locale** (Symfony routing / locale switcher). Appearance shows the active locale read-only. **Theme** and other appearance options apply on the dashboard and settings UI. See [TRANSLATIONS.md](TRANSLATIONS.md).

## Notifications

The settings page does **not** edit notification providers. Configure channels in the host application:

```yaml
nowo_uptime_monitor:
    notifications:
        enabled: true
        cooldown_seconds: 300
        email: { enabled: true, from: '...', to: ['...'] }
        webhook: { enabled: true, url: '...' }
        slack: { enabled: true, webhook_url: '...' }
```

Per-monitor behaviour (retries before down, resend alert every N failed checks) is configured on each monitor form. See [NOTIFICATIONS.md](NOTIFICATIONS.md).

## Reverse proxy

| Setting key | Default | Notes |
|-------------|---------|-------|
| `trusted_proxy` | `false` | When true, documents that the app is behind a reverse proxy |

Symfony must also trust proxies in the host app (`framework.trusted_proxies` / `trusted_headers`). The bundle flag is for operator documentation and future request handling.

Cloudflare Tunnel and other tunnel UIs from Uptime Kuma are **not** bundled.

## Tags

Tags are first-class entities (`uptime_tag`: `name`, optional `color`, scoped to tenant).

Monitors can still store tag names in `monitor.config.tags` (comma-separated on the monitor form). Global tag definitions in settings help organize filters (sidebar filter UI may be extended later).

## Monitor history

| Setting | Description |
|---------|-------------|
| Use global YAML default | Uses `nowo_uptime_monitor.retention.detail_days` |
| Tenant `detail_days` | Override retention for this tenant only |
| `0` days | Infinite retention (no automatic purge for that tenant) |

Actions:

- **Purge expired detail rows** — deletes `CheckResult` rows older than the effective retention for the tenant.
- **Clear all statistics** — removes checks, aggregates, and incidents for the tenant; resets monitor status hints.

Global retention is documented in [CONFIGURATION.md](CONFIGURATION.md).

## Backup

### Export

`GET /uptime/{tenantSlug}/settings/backup/export`

Returns JSON:

```json
{
  "version": 1,
  "exported_at": "2026-05-20T12:00:00+00:00",
  "tenant": "main",
  "monitors": [ { "name": "...", "type": "https", "config": { ... } } ]
}
```

History, incidents, and notification provider secrets from YAML are **not** included (same scope as Uptime Kuma’s legacy JSON backup).

### Import

`POST /uptime/{tenantSlug}/settings/backup` with `backup` file and `import_mode`:

| Mode | Behaviour |
|------|-----------|
| `skip` | Skip monitors whose name already exists |
| `keep` | Same as skip (duplicate names are not duplicated) |
| `overwrite` | Update existing monitors by name |

## Not implemented (Uptime Kuma only)

These Kuma settings screens have no bundle equivalent yet:

- Docker hosts
- API keys
- Global outbound proxy list (use per-monitor `config.proxy` on HTTP monitors)
- Cloudflare tunnel installer
- Chrome/Chromium auto-install in Docker

## Programmatic access

```php
use Nowo\UptimeMonitorBundle\Monitor\TenantSettings;

$settings = TenantSettings::from($tenant);
$theme = $settings->getTheme();
$settings->merge(['theme' => 'dark']);
$entityManager->flush();
```
