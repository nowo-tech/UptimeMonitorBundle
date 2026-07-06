<?php

declare(strict_types=1);

namespace Nowo\UptimeMonitorBundle\Monitor;

use Nowo\UptimeMonitorBundle\Entity\Monitor;
use Nowo\UptimeMonitorBundle\Enum\MonitorType;

use function in_array;
use function is_array;
use function is_string;

/**
 * Typed access to monitor JSON config (Uptime Kuma–compatible fields).
 */
final class MonitorSettings
{
    private const FAILURE_STREAK_KEY = 'failure_streak';

    public function __construct(
        private readonly Monitor $monitor,
    ) {
    }

    public static function from(Monitor $monitor): self
    {
        return new self($monitor);
    }

    public function getDescription(): ?string
    {
        $value = $this->monitor->getConfig()['description'] ?? null;

        return is_string($value) && $value !== '' ? $value : null;
    }

    public function getRequestTimeoutSeconds(): float
    {
        $timeout = $this->monitor->getConfig()['timeout'] ?? $this->monitor->getConfig()['request_timeout_seconds'] ?? 10;

        return max(1.0, (float) $timeout);
    }

    public function getMaxRedirects(): int
    {
        $value = $this->monitor->getConfig()['max_redirects'] ?? 10;

        return max(0, (int) $value);
    }

    public function isIgnoreTlsErrors(): bool
    {
        return ($this->monitor->getConfig()['verify_ssl'] ?? true) === false
            || ($this->monitor->getConfig()['ignore_tls'] ?? false) === true;
    }

    public function isUpsideDown(): bool
    {
        return ($this->monitor->getConfig()['upside_down'] ?? false) === true;
    }

    public function isCheckCertExpiry(): bool
    {
        return ($this->monitor->getConfig()['check_cert_expiry'] ?? false) === true;
    }

    public function getResendNotificationAfterDownCount(): int
    {
        $value = $this->monitor->getConfig()['resend_notification_after_down'] ?? 0;

        return max(0, (int) $value);
    }

    /** @return list<string> */
    public function getTags(): array
    {
        $tags = $this->monitor->getConfig()['tags'] ?? [];

        if (!is_array($tags)) {
            return [];
        }

        return array_values(array_filter(array_map(
            static fn (mixed $tag): string => is_string($tag) ? trim($tag) : '',
            $tags,
        )));
    }

    public function getProxyUrl(): ?string
    {
        $value = $this->monitor->getConfig()['proxy'] ?? null;

        return is_string($value) && $value !== '' ? $value : null;
    }

    public function getAuthMethod(): string
    {
        $value = $this->monitor->getConfig()['auth_method'] ?? 'none';

        return is_string($value) ? $value : 'none';
    }

    public function getAuthUsername(): string
    {
        $value = $this->monitor->getConfig()['auth_username'] ?? '';

        return is_string($value) ? $value : '';
    }

    public function getAuthPassword(): string
    {
        $value = $this->monitor->getConfig()['auth_password'] ?? '';

        return is_string($value) ? $value : '';
    }

    public function getHttpBody(): ?string
    {
        $value = $this->monitor->getConfig()['body'] ?? null;

        return is_string($value) && $value !== '' ? $value : null;
    }

    public function getBodyEncoding(): string
    {
        $value = $this->monitor->getConfig()['body_encoding'] ?? 'json';

        return is_string($value) ? $value : 'json';
    }

    /** @return array<string, string> */
    public function getHeaders(): array
    {
        $headers = $this->monitor->getConfig()['headers'] ?? [];

        return is_array($headers) ? $headers : [];
    }

    public function getFailureStreak(): int
    {
        $value = $this->monitor->getConfig()[self::FAILURE_STREAK_KEY] ?? 0;

        return max(0, (int) $value);
    }

    public function setFailureStreak(int $streak): void
    {
        $config                           = $this->monitor->getConfig();
        $config[self::FAILURE_STREAK_KEY] = max(0, $streak);
        $this->monitor->setConfig($config);
    }

    public function resetFailureStreak(): void
    {
        $this->setFailureStreak(0);
    }

    /**
     * @return list<int>
     */
    public function getExpectedStatusCodes(): array
    {
        $raw = $this->monitor->getConfig()['expected_status_codes'] ?? [200];

        if (is_string($raw)) {
            return StatusCodeMatcher::parse($raw);
        }

        if (!is_array($raw)) {
            return [200];
        }

        return StatusCodeMatcher::parse(implode(',', array_map('strval', $raw)));
    }

    public function appliesToHttp(): bool
    {
        return in_array($this->monitor->getType(), [MonitorType::Http, MonitorType::Https], true);
    }
}
