<?php

declare(strict_types=1);

namespace Nowo\UptimeMonitorBundle\Service;

use Nowo\UptimeMonitorBundle\Entity\Tenant;
use Nowo\UptimeMonitorBundle\Form\Model\SettingsAppearanceData;
use Nowo\UptimeMonitorBundle\Form\Model\SettingsGeneralData;
use Nowo\UptimeMonitorBundle\Form\Model\SettingsHistoryData;
use Nowo\UptimeMonitorBundle\Form\Model\SettingsReverseProxyData;
use Nowo\UptimeMonitorBundle\Form\Model\SettingsStatusPageData;
use Nowo\UptimeMonitorBundle\Monitor\TenantSettings;
use Nowo\UptimeMonitorBundle\Ui\UiFramework;

use function array_map;
use function intval;

/**
 * Maps tenant settings JSON ↔ form models.
 */
final class TenantSettingsMapper
{
    /**
     * Builds the General settings form model from the tenant settings.
     */
    public function toGeneralData(Tenant $tenant): SettingsGeneralData
    {
        $s                       = TenantSettings::from($tenant);
        $data                    = new SettingsGeneralData();
        $data->displayTimezone   = $s->getDisplayTimezone();
        $data->serverTimezone    = $s->getServerTimezone();
        $data->searchEngineIndex = $s->isSearchEngineIndexAllowed();
        $data->entryPage         = $s->getEntryPage();
        $data->primaryBaseUrl    = $s->getPrimaryBaseUrl() ?? '';
        $data->nscdEnabled       = $s->isNscdEnabled();
        $data->httpDnsCache      = $s->isHttpDnsCacheEnabled();

        return $data;
    }

    /**
     * Stores the submitted General settings on the tenant (empty base URL / Steam key become null).
     */
    public function applyGeneral(Tenant $tenant, SettingsGeneralData $data): void
    {
        // Former Uptime Kuma parity fields that were stored but never used (the Steam key was a secret in a plain
        // text input): dropped on the next save.
        $settings = $tenant->getSettings();
        unset($settings['steam_api_key'], $settings['chromium_executable']);
        $tenant->setSettings($settings);

        TenantSettings::from($tenant)->merge([
            'display_timezone'    => $data->displayTimezone,
            'server_timezone'     => $data->serverTimezone,
            'search_engine_index' => $data->searchEngineIndex,
            'entry_page'          => $data->entryPage,
            'primary_base_url'    => $data->primaryBaseUrl !== '' ? $data->primaryBaseUrl : null,
            'nscd_enabled'        => $data->nscdEnabled,
            'http_dns_cache'      => $data->httpDnsCache,
        ]);
    }

    /**
     * Builds the Status page settings form model from the tenant settings (since 1.13).
     */
    public function toStatusPageData(Tenant $tenant): SettingsStatusPageData
    {
        $s                = TenantSettings::from($tenant);
        $data             = new SettingsStatusPageData();
        $data->visibility = $s->getStatusPageVisibility();
        $data->monitorIds = $s->getStatusPageMonitorIds();

        return $data;
    }

    /**
     * Stores who may open the public status page and the monitors it shows (since 1.13).
     */
    public function applyStatusPage(Tenant $tenant, SettingsStatusPageData $data): void
    {
        TenantSettings::from($tenant)->merge([
            'status_page_public'   => $data->visibility,
            'status_page_monitors' => array_map(intval(...), $data->monitorIds),
        ]);
    }

    /**
     * Builds the Appearance settings form model from the tenant settings.
     */
    public function toAppearanceData(Tenant $tenant): SettingsAppearanceData
    {
        $s                       = TenantSettings::from($tenant);
        $data                    = new SettingsAppearanceData();
        $data->theme             = $s->getTheme();
        $data->heartbeatBarTheme = $s->getHeartbeatBarTheme();
        $data->elapsedTime       = $s->getElapsedTimeDisplay();
        $uiOverride              = $s->getUiFrameworkOverride();
        $data->uiFramework       = $uiOverride instanceof UiFramework ? $uiOverride->value : 'default';

        return $data;
    }

    /**
     * Stores the submitted Appearance settings on the tenant and drops the legacy `language` key.
     */
    public function applyAppearance(Tenant $tenant, SettingsAppearanceData $data): void
    {
        $settings = $tenant->getSettings();
        unset($settings['language']);

        $patch = [
            'theme'               => $data->theme,
            'heartbeat_bar_theme' => $data->heartbeatBarTheme,
            'elapsed_time'        => $data->elapsedTime,
        ];

        if ($data->uiFramework === '' || $data->uiFramework === 'default') {
            unset($settings['ui_framework']);
        } else {
            $settings['ui_framework'] = $data->uiFramework;
        }

        $tenant->setSettings(array_merge($settings, $patch));
    }

    /**
     * Builds the Monitor history form model: the tenant override, or the global default when none is set.
     *
     * @param int $globalDetailDays Global `retention.detail_days`
     */
    public function toHistoryData(Tenant $tenant, int $globalDetailDays): SettingsHistoryData
    {
        $data     = new SettingsHistoryData();
        $override = TenantSettings::from($tenant)->getDetailRetentionDays();
        if ($override === null) {
            $data->useGlobalDefault = true;
            $data->detailDays       = $globalDetailDays;

            return $data;
        }

        $data->useGlobalDefault = false;
        $data->detailDays       = $override;

        return $data;
    }

    /**
     * Stores the tenant retention override, or removes it when the global default is chosen.
     */
    public function applyHistory(Tenant $tenant, SettingsHistoryData $data): void
    {
        if ($data->useGlobalDefault) {
            $settings = $tenant->getSettings();
            unset($settings['detail_days']);
            $tenant->setSettings($settings);

            return;
        }

        TenantSettings::from($tenant)->merge(['detail_days' => max(0, $data->detailDays)]);
    }

    /**
     * Builds the Reverse proxy settings form model from the tenant settings.
     */
    public function toReverseProxyData(Tenant $tenant): SettingsReverseProxyData
    {
        $data               = new SettingsReverseProxyData();
        $data->trustedProxy = TenantSettings::from($tenant)->isTrustedProxy();

        return $data;
    }

    /**
     * Stores the submitted Reverse proxy settings on the tenant.
     */
    public function applyReverseProxy(Tenant $tenant, SettingsReverseProxyData $data): void
    {
        TenantSettings::from($tenant)->merge(['trusted_proxy' => $data->trustedProxy]);
    }
}
