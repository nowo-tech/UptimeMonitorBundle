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
final readonly class TenantSettings
{
    /**
     * Wraps the tenant settings.
     *
     * @param Tenant $tenant Tenant whose settings are read
     */
    public function __construct(
        private Tenant $tenant,
    ) {
    }

    /**
     * Creates the accessor for a tenant.
     */
    public static function from(Tenant $tenant): self
    {
        return new self($tenant);
    }

    /** @return array<string, mixed> */
    public function all(): array
    {
        return $this->tenant->getSettings();
    }

    /**
     * Returns the display time zone (`auto` = the browser one).
     */
    public function getDisplayTimezone(): string
    {
        return $this->string('display_timezone', 'auto');
    }

    /**
     * Returns the server time zone (default UTC).
     */
    public function getServerTimezone(): string
    {
        return $this->string('server_timezone', 'UTC');
    }

    /**
     * Whether search engines may index the pages.
     */
    public function isSearchEngineIndexAllowed(): bool
    {
        return $this->bool('search_engine_index', false);
    }

    /**
     * Returns the entry page (`dashboard` or `status`).
     */
    public function getEntryPage(): string
    {
        return $this->string('entry_page', 'dashboard');
    }

    /**
     * Returns the primary base URL, if set.
     */
    public function getPrimaryBaseUrl(): ?string
    {
        $url = $this->string('primary_base_url', '');

        return $url !== '' ? $url : null;
    }

    /**
     * Returns the Steam API key (Uptime Kuma parity setting).
     */
    public function getSteamApiKey(): string
    {
        return $this->string('steam_api_key', '');
    }

    /**
     * Whether NSCD caching is enabled (Uptime Kuma parity setting).
     */
    public function isNscdEnabled(): bool
    {
        return $this->bool('nscd_enabled', true);
    }

    /**
     * Whether the HTTP DNS cache is enabled (Uptime Kuma parity setting).
     */
    public function isHttpDnsCacheEnabled(): bool
    {
        return $this->bool('http_dns_cache', false);
    }

    /**
     * Returns the Chromium executable (`auto` by default).
     */
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

    /**
     * Returns the theme (`light`, `dark` or `auto`).
     */
    public function getTheme(): string
    {
        return $this->string('theme', 'auto');
    }

    /**
     * Returns the heartbeat bar placement (`normal`, `bottom` or `none`).
     */
    public function getHeartbeatBarTheme(): string
    {
        return $this->string('heartbeat_bar_theme', 'normal');
    }

    /**
     * Returns how elapsed times are shown (`show`, `show_line` or `none`).
     */
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

    /**
     * Whether the reverse proxy headers are trusted.
     */
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
        // @igor-ignore - Not shared worker service state.
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
