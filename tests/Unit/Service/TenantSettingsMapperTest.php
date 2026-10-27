<?php

declare(strict_types=1);

namespace Nowo\UptimeMonitorBundle\Tests\Unit\Service;

use Nowo\UptimeMonitorBundle\Entity\Tenant;
use Nowo\UptimeMonitorBundle\Form\Model\SettingsAppearanceData;
use Nowo\UptimeMonitorBundle\Form\Model\SettingsGeneralData;
use Nowo\UptimeMonitorBundle\Form\Model\SettingsHistoryData;
use Nowo\UptimeMonitorBundle\Form\Model\SettingsReverseProxyData;
use Nowo\UptimeMonitorBundle\Service\TenantSettingsMapper;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(TenantSettingsMapper::class)]
final class TenantSettingsMapperTest extends TestCase
{
    public function testGeneralRoundTrip(): void
    {
        $tenant = (new Tenant('main', 'Main'))->setSettings(['steam_api_key' => 'legacy-secret', 'chromium_executable' => '/bin/chromium']);
        $mapper = new TenantSettingsMapper();

        $defaults = $mapper->toGeneralData($tenant);
        self::assertSame('default', $defaults->statusPageVisibility);
        self::assertSame('', $defaults->primaryBaseUrl);
        self::assertTrue($defaults->nscdEnabled);

        $data                       = new SettingsGeneralData();
        $data->displayTimezone      = 'Europe/Madrid';
        $data->serverTimezone       = 'UTC';
        $data->searchEngineIndex    = true;
        $data->entryPage            = 'status';
        $data->primaryBaseUrl       = 'https://status.test';
        $data->nscdEnabled          = false;
        $data->httpDnsCache         = true;
        $data->statusPageVisibility = 'private';
        $mapper->applyGeneral($tenant, $data);

        // Stored-but-unused legacy settings are dropped on save (the Steam key was a secret).
        self::assertArrayNotHasKey('steam_api_key', $tenant->getSettings());
        self::assertArrayNotHasKey('chromium_executable', $tenant->getSettings());
        self::assertSame('private', $tenant->getSettings()['status_page_public']);
        self::assertEquals($data, $mapper->toGeneralData($tenant));

        $data->primaryBaseUrl = '';
        $mapper->applyGeneral($tenant, $data);
        self::assertNull($tenant->getSettings()['primary_base_url']);
    }

    public function testAppearanceRoundTripDropsLegacyLanguageAndDefaultFramework(): void
    {
        $tenant = (new Tenant('main', 'Main'))->setSettings(['language' => 'es', 'keep' => 1]);
        $mapper = new TenantSettingsMapper();

        self::assertSame('default', $mapper->toAppearanceData($tenant)->uiFramework);

        $data                    = new SettingsAppearanceData();
        $data->theme             = 'dark';
        $data->heartbeatBarTheme = 'none';
        $data->elapsedTime       = 'show_line';
        $data->uiFramework       = 'bootstrap';
        $mapper->applyAppearance($tenant, $data);

        self::assertArrayNotHasKey('language', $tenant->getSettings());
        self::assertSame(1, $tenant->getSettings()['keep']);
        self::assertEquals($data, $mapper->toAppearanceData($tenant));

        $data->uiFramework = 'default';
        $mapper->applyAppearance($tenant, $data);
        self::assertArrayNotHasKey('ui_framework', $tenant->getSettings());
    }

    public function testHistoryUsesTheGlobalDefaultOrTheTenantOverride(): void
    {
        $tenant = new Tenant('main', 'Main');
        $mapper = new TenantSettingsMapper();

        $global = $mapper->toHistoryData($tenant, 30);
        self::assertTrue($global->useGlobalDefault);
        self::assertSame(30, $global->detailDays);

        $data                   = new SettingsHistoryData();
        $data->useGlobalDefault = false;
        $data->detailDays       = -4;
        $mapper->applyHistory($tenant, $data);
        $override = $mapper->toHistoryData($tenant, 30);
        self::assertFalse($override->useGlobalDefault);
        self::assertSame(0, $override->detailDays);

        $data->useGlobalDefault = true;
        $mapper->applyHistory($tenant, $data);
        self::assertArrayNotHasKey('detail_days', $tenant->getSettings());
    }

    public function testReverseProxyRoundTrip(): void
    {
        $tenant = new Tenant('main', 'Main');
        $mapper = new TenantSettingsMapper();

        self::assertFalse($mapper->toReverseProxyData($tenant)->trustedProxy);

        $data               = new SettingsReverseProxyData();
        $data->trustedProxy = true;
        $mapper->applyReverseProxy($tenant, $data);

        self::assertTrue($mapper->toReverseProxyData($tenant)->trustedProxy);
    }
}
