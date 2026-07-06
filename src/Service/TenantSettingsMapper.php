<?php

declare(strict_types=1);

namespace Nowo\UptimeMonitorBundle\Service;

use Nowo\UptimeMonitorBundle\Entity\Tenant;
use Nowo\UptimeMonitorBundle\Form\Model\SettingsAppearanceData;
use Nowo\UptimeMonitorBundle\Form\Model\SettingsGeneralData;
use Nowo\UptimeMonitorBundle\Form\Model\SettingsHistoryData;
use Nowo\UptimeMonitorBundle\Form\Model\SettingsReverseProxyData;
use Nowo\UptimeMonitorBundle\Monitor\TenantSettings;

/**
 * Maps tenant settings JSON ↔ form models.
 */
final class TenantSettingsMapper
{
    public function toGeneralData(Tenant $tenant): SettingsGeneralData
    {
        $s                        = TenantSettings::from($tenant);
        $data                     = new SettingsGeneralData();
        $data->displayTimezone    = $s->getDisplayTimezone();
        $data->serverTimezone     = $s->getServerTimezone();
        $data->searchEngineIndex  = $s->isSearchEngineIndexAllowed();
        $data->entryPage          = $s->getEntryPage();
        $data->primaryBaseUrl     = $s->getPrimaryBaseUrl() ?? '';
        $data->steamApiKey        = $s->getSteamApiKey();
        $data->nscdEnabled        = $s->isNscdEnabled();
        $data->httpDnsCache       = $s->isHttpDnsCacheEnabled();
        $data->chromiumExecutable = $s->getChromiumExecutable();

        return $data;
    }

    public function applyGeneral(Tenant $tenant, SettingsGeneralData $data): void
    {
        TenantSettings::from($tenant)->merge([
            'display_timezone'    => $data->displayTimezone,
            'server_timezone'     => $data->serverTimezone,
            'search_engine_index' => $data->searchEngineIndex,
            'entry_page'          => $data->entryPage,
            'primary_base_url'    => $data->primaryBaseUrl !== '' ? $data->primaryBaseUrl : null,
            'steam_api_key'       => $data->steamApiKey !== '' ? $data->steamApiKey : null,
            'nscd_enabled'        => $data->nscdEnabled,
            'http_dns_cache'      => $data->httpDnsCache,
            'chromium_executable' => $data->chromiumExecutable,
        ]);
    }

    public function toAppearanceData(Tenant $tenant): SettingsAppearanceData
    {
        $s                       = TenantSettings::from($tenant);
        $data                    = new SettingsAppearanceData();
        $data->theme             = $s->getTheme();
        $data->heartbeatBarTheme = $s->getHeartbeatBarTheme();
        $data->elapsedTime       = $s->getElapsedTimeDisplay();
        $data->uiFramework       = $s->getUiFrameworkOverride()?->value ?? 'default';

        return $data;
    }

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

    public function applyHistory(Tenant $tenant, SettingsHistoryData $data, int $globalDetailDays): void
    {
        if ($data->useGlobalDefault) {
            $settings = $tenant->getSettings();
            unset($settings['detail_days']);
            $tenant->setSettings($settings);

            return;
        }

        TenantSettings::from($tenant)->merge(['detail_days' => max(0, $data->detailDays)]);
    }

    public function toReverseProxyData(Tenant $tenant): SettingsReverseProxyData
    {
        $data               = new SettingsReverseProxyData();
        $data->trustedProxy = TenantSettings::from($tenant)->isTrustedProxy();

        return $data;
    }

    public function applyReverseProxy(Tenant $tenant, SettingsReverseProxyData $data): void
    {
        TenantSettings::from($tenant)->merge(['trusted_proxy' => $data->trustedProxy]);
    }
}
