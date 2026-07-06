# Notifications

Channels: **email**, **webhook**, **Slack** (incoming webhook).

```yaml
nowo_uptime_monitor:
    notifications:
        enabled: true
        cooldown_seconds: 300
        email:
            enabled: true
            from: 'uptime@example.com'
            to: ['ops@example.com']
        webhook:
            enabled: true
            url: 'https://hooks.example.com/uptime'
        slack:
            enabled: true
            webhook_url: 'https://hooks.slack.com/services/XXX'
```

Requires `symfony/mailer` for email. Alerts fire on status transitions (up↔down) with cooldown per monitor.
