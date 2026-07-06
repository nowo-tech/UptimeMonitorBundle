<?php

declare(strict_types=1);

namespace Nowo\UptimeMonitorBundle\Monitor;

use Nowo\UptimeMonitorBundle\Entity\Tenant;
use Nowo\UptimeMonitorBundle\Ui\UiFramework;

use function array_key_exists;
use function is_bool;
use function is_string;

/**
 * Typed access to per-tenant settings (Uptime Kuma Settings screen).
 */
final class TenantSettings
{
    public function __construct(
        private readonly Tenant $tenant,
    ) {
    }

    public static function from(Tenant $tenant): self
    {
        return new self($tenant);
    }

    /** @return array<string, mixed> */
    public function all(): array
    {
        return $this->tenant->getSettings();
    }

    public function getDisplayTimezone(): string
    {
        return $this->string('display_timezone', 'auto');
    }

    public function getServerTimezone(): string
    {
        return $this->string('server_timezone', 'UTC');
    }

    public function isSearchEngineIndexAllowed(): bool
    {
        return $this->bool('search_engine_index', false);
    }

    public function getEntryPage(): string
    {
        return $this->string('entry_page', 'dashboard');
    }

    public function getPrimaryBaseUrl(): ?string
    {
        $url = $this->string('primary_base_url', '');

        return $url !== '' ? $url : null;
    }

    public function getSteamApiKey(): string
    {
        return $this->string('steam_api_key', '');
    }

    public function isNscdEnabled(): bool
    {
        return $this->bool('nscd_enabled', true);
    }

    public function isHttpDnsCacheEnabled(): bool
    {
        return $this->bool('http_dns_cache', false);
    }

    public function getChromiumExecutable(): string
    {
        return $this->string('chromium_executable', 'auto');
    }

    /**
     * @deprecated language is resolved from the Symfony session locale, not tenant settings
     */
    public function getLanguage(): string
    {
        return $this->string('language', 'en');
    }

    public function getTheme(): string
    {
        return $this->string('theme', 'auto');
    }

    public function getHeartbeatBarTheme(): string
    {
        return $this->string('heartbeat_bar_theme', 'normal');
    }

    public function getElapsedTimeDisplay(): string
    {
        return $this->string('elapsed_time', 'show');
    }

    /** null = use global {@see Configuration} ui.framework */
    public function getUiFrameworkOverride(): ?UiFramework
    {
        if (!array_key_exists('ui_framework', $this->tenant->getSettings())) {
            return null;
        }

        $value = $this->tenant->getSettings()['ui_framework'];
        if (!is_string($value) || $value === '' || $value === 'default') {
            return null;
        }

        return UiFramework::fromString($value);
    }

    public function isTrustedProxy(): bool
    {
        return $this->bool('trusted_proxy', false);
    }

    /** null = use global YAML retention */
    public function getDetailRetentionDays(): ?int
    {
        if (!array_key_exists('detail_days', $this->tenant->getSettings())) {
            return null;
        }

        $days = $this->tenant->getSettings()['detail_days'];

        return is_numeric($days) ? max(0, (int) $days) : null;
    }

    /**
     * @param array<string, mixed> $patch
     */
    public function merge(array $patch): void
    {
        $this->tenant->setSettings(array_merge($this->tenant->getSettings(), $patch));
    }

    private function string(string $key, string $default): string
    {
        $value = $this->tenant->getSettings()[$key] ?? $default;

        return is_string($value) ? $value : $default;
    }

    private function bool(string $key, bool $default): bool
    {
        $value = $this->tenant->getSettings()[$key] ?? $default;

        return is_bool($value) ? $value : $default;
    }
}
