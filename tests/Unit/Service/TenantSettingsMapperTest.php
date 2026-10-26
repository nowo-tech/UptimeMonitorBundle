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
        $tenant = new Tenant('main', 'Main');
        $mapper = new TenantSettingsMapper();

        $defaults = $mapper->toGeneralData($tenant);
        self::assertSame('', $defaults->primaryBaseUrl);
        self::assertTrue($defaults->nscdEnabled);

        $data                     = new SettingsGeneralData();
        $data->displayTimezone    = 'Europe/Madrid';
        $data->serverTimezone     = 'UTC';
        $data->searchEngineIndex  = true;
        $data->entryPage          = 'status';
        $data->primaryBaseUrl     = 'https://status.test';
        $data->steamApiKey        = '';
        $data->nscdEnabled        = false;
        $data->httpDnsCache       = true;
        $data->chromiumExecutable = '/bin/chromium';
        $mapper->applyGeneral($tenant, $data);

        self::assertNull($tenant->getSettings()['steam_api_key']);
        self::assertEquals($data, $mapper->toGeneralData($tenant));

        $data->primaryBaseUrl = '';
        $data->steamApiKey    = 'key';
        $mapper->applyGeneral($tenant, $data);
        self::assertNull($tenant->getSettings()['primary_base_url']);
        self::assertSame('key', $mapper->toGeneralData($tenant)->steamApiKey);
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
