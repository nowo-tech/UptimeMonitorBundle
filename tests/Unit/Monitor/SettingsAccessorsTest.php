<?php

declare(strict_types=1);

namespace Nowo\UptimeMonitorBundle\Tests\Unit\Monitor;

use Nowo\UptimeMonitorBundle\Entity\Monitor;
use Nowo\UptimeMonitorBundle\Entity\Tenant;
use Nowo\UptimeMonitorBundle\Enum\MonitorType;
use Nowo\UptimeMonitorBundle\Monitor\HttpHeaderParser;
use Nowo\UptimeMonitorBundle\Monitor\MonitorSettings;
use Nowo\UptimeMonitorBundle\Monitor\TenantSettings;
use Nowo\UptimeMonitorBundle\Ui\UiFramework;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(MonitorSettings::class)]
#[CoversClass(TenantSettings::class)]
#[CoversClass(HttpHeaderParser::class)]
#[CoversClass(UiFramework::class)]
final class SettingsAccessorsTest extends TestCase
{
    /**
     * @param array<string, mixed> $config
     */
    private function monitor(array $config, MonitorType $type = MonitorType::Https): Monitor
    {
        $monitor = new Monitor(new Tenant('main', 'Main'), 'API', $type, 'https://example.test');
        $monitor->setConfig($config);

        return $monitor;
    }

    public function testMonitorSettingsDefaults(): void
    {
        $settings = MonitorSettings::from($this->monitor([]));

        self::assertNull($settings->getDescription());
        self::assertSame(10.0, $settings->getRequestTimeoutSeconds());
        self::assertSame(10, $settings->getMaxRedirects());
        self::assertFalse($settings->isIgnoreTlsErrors());
        self::assertFalse($settings->isUpsideDown());
        self::assertFalse($settings->isCheckCertExpiry());
        self::assertSame(0, $settings->getResendNotificationAfterDownCount());
        self::assertSame([], $settings->getTags());
        self::assertNull($settings->getProxyUrl());
        self::assertSame('none', $settings->getAuthMethod());
        self::assertSame('', $settings->getAuthUsername());
        self::assertSame('', $settings->getAuthPassword());
        self::assertNull($settings->getHttpBody());
        self::assertSame('json', $settings->getBodyEncoding());
        self::assertSame([], $settings->getHeaders());
        self::assertSame(0, $settings->getFailureStreak());
        self::assertSame([200], $settings->getExpectedStatusCodes());
        self::assertTrue($settings->appliesToHttp());
    }

    public function testMonitorSettingsReadConfiguredValues(): void
    {
        $settings = MonitorSettings::from($this->monitor([
            'description'                    => 'Main API',
            'request_timeout_seconds'        => 0.2,
            'max_redirects'                  => -3,
            'ignore_tls'                     => true,
            'upside_down'                    => true,
            'check_cert_expiry'              => true,
            'resend_notification_after_down' => 3,
            'tags'                           => [' prod ', 7, 'eu'],
            'proxy'                          => 'http://proxy.test:3128',
            'auth_method'                    => 'basic',
            'auth_username'                  => 'user',
            'auth_password'                  => 'secret',
            'body'                           => '{"a":1}',
            'body_encoding'                  => 'xml',
            'headers'                        => ['X-Test' => '1'],
            'failure_streak'                 => 4,
            'expected_status_codes'          => '200-201, 404',
        ]));

        self::assertSame('Main API', $settings->getDescription());
        self::assertSame(1.0, $settings->getRequestTimeoutSeconds());
        self::assertSame(0, $settings->getMaxRedirects());
        self::assertTrue($settings->isIgnoreTlsErrors());
        self::assertTrue($settings->isUpsideDown());
        self::assertTrue($settings->isCheckCertExpiry());
        self::assertSame(3, $settings->getResendNotificationAfterDownCount());
        self::assertSame(['prod', 'eu'], $settings->getTags());
        self::assertSame('http://proxy.test:3128', $settings->getProxyUrl());
        self::assertSame('basic', $settings->getAuthMethod());
        self::assertSame('user', $settings->getAuthUsername());
        self::assertSame('secret', $settings->getAuthPassword());
        self::assertSame('{"a":1}', $settings->getHttpBody());
        self::assertSame('xml', $settings->getBodyEncoding());
        self::assertSame(['X-Test' => '1'], $settings->getHeaders());
        self::assertSame(4, $settings->getFailureStreak());
        self::assertSame([200, 201, 404], $settings->getExpectedStatusCodes());
    }

    public function testMonitorSettingsFallbacksForWrongTypes(): void
    {
        $settings = MonitorSettings::from($this->monitor([
            'timeout'               => 30,
            'verify_ssl'            => false,
            'tags'                  => 'not-a-list',
            'auth_method'           => 1,
            'auth_username'         => 1,
            'auth_password'         => 1,
            'body_encoding'         => 1,
            'headers'               => 'X-Test: 1',
            'expected_status_codes' => 200,
        ], MonitorType::Tcp));

        self::assertSame(30.0, $settings->getRequestTimeoutSeconds());
        self::assertTrue($settings->isIgnoreTlsErrors());
        self::assertSame([], $settings->getTags());
        self::assertSame('none', $settings->getAuthMethod());
        self::assertSame('', $settings->getAuthUsername());
        self::assertSame('', $settings->getAuthPassword());
        self::assertSame('json', $settings->getBodyEncoding());
        self::assertSame([], $settings->getHeaders());
        self::assertSame([200], $settings->getExpectedStatusCodes());
        self::assertFalse($settings->appliesToHttp());

        $list = MonitorSettings::from($this->monitor(['expected_status_codes' => [301, 302]]));
        self::assertSame([301, 302], $list->getExpectedStatusCodes());
    }

    public function testFailureStreakIsStoredInConfig(): void
    {
        $monitor  = $this->monitor(['keep' => true]);
        $settings = MonitorSettings::from($monitor);

        $settings->setFailureStreak(-2);
        self::assertSame(0, $monitor->getConfig()['failure_streak']);

        $settings->setFailureStreak(5);
        self::assertSame(5, $settings->getFailureStreak());

        $settings->resetFailureStreak();
        self::assertSame(['keep' => true, 'failure_streak' => 0], $monitor->getConfig());
    }

    public function testTenantSettingsDefaults(): void
    {
        $settings = TenantSettings::from(new Tenant('main', 'Main'));

        self::assertSame([], $settings->all());
        self::assertSame('auto', $settings->getDisplayTimezone());
        self::assertSame('UTC', $settings->getServerTimezone());
        self::assertFalse($settings->isSearchEngineIndexAllowed());
        self::assertSame('dashboard', $settings->getEntryPage());
        self::assertNull($settings->getPrimaryBaseUrl());
        self::assertSame('default', $settings->getStatusPageVisibility());
        self::assertTrue($settings->isStatusPagePublic(true));
        self::assertFalse($settings->isStatusPagePublic(false));
        self::assertTrue($settings->isNscdEnabled());
        self::assertFalse($settings->isHttpDnsCacheEnabled());
        self::assertSame('en', $settings->getLanguage());
        self::assertSame('auto', $settings->getTheme());
        self::assertSame('normal', $settings->getHeartbeatBarTheme());
        self::assertSame('show', $settings->getElapsedTimeDisplay());
        self::assertNull($settings->getUiFrameworkOverride());
        self::assertFalse($settings->isTrustedProxy());
        self::assertNull($settings->getDetailRetentionDays());
    }

    public function testTenantSettingsReadAndMergeValues(): void
    {
        $tenant   = new Tenant('main', 'Main');
        $settings = TenantSettings::from($tenant);
        $settings->merge([
            'display_timezone'    => 'Europe/Madrid',
            'server_timezone'     => 'Europe/Paris',
            'search_engine_index' => true,
            'entry_page'          => 'status',
            'primary_base_url'    => 'https://status.test',
            'status_page_public'  => 'private',
            'nscd_enabled'        => false,
            'http_dns_cache'      => true,
            'language'            => 'es',
            'theme'               => 'dark',
            'heartbeat_bar_theme' => 'bottom',
            'elapsed_time'        => 'none',
            'ui_framework'        => 'bootstrap',
            'trusted_proxy'       => true,
            'detail_days'         => '-5',
        ]);

        self::assertSame('Europe/Madrid', $settings->getDisplayTimezone());
        self::assertSame('Europe/Paris', $settings->getServerTimezone());
        self::assertTrue($settings->isSearchEngineIndexAllowed());
        self::assertSame('status', $settings->getEntryPage());
        self::assertSame('https://status.test', $settings->getPrimaryBaseUrl());
        self::assertSame('private', $settings->getStatusPageVisibility());
        self::assertFalse($settings->isStatusPagePublic(true));
        $settings->merge(['status_page_public' => 'public']);
        self::assertTrue($settings->isStatusPagePublic(false));
        $settings->merge(['status_page_public' => 'bogus']);
        self::assertSame('default', $settings->getStatusPageVisibility());
        $settings->merge(['status_page_public' => 'private']);
        self::assertFalse($settings->isNscdEnabled());
        self::assertTrue($settings->isHttpDnsCacheEnabled());
        self::assertSame('es', $settings->getLanguage());
        self::assertSame('dark', $settings->getTheme());
        self::assertSame('bottom', $settings->getHeartbeatBarTheme());
        self::assertSame('none', $settings->getElapsedTimeDisplay());
        self::assertSame(UiFramework::Bootstrap, $settings->getUiFrameworkOverride());
        self::assertTrue($settings->isTrustedProxy());
        self::assertSame(0, $settings->getDetailRetentionDays());

        $settings->merge(['ui_framework' => 'unknown', 'detail_days' => 'n/a', 'theme' => 3, 'trusted_proxy' => 'yes']);
        self::assertSame(UiFramework::Tabler, $settings->getUiFrameworkOverride());
        self::assertNull($settings->getDetailRetentionDays());
        self::assertSame('auto', $settings->getTheme());
        self::assertFalse($settings->isTrustedProxy());

        $settings->merge(['ui_framework' => 'default']);
        self::assertNull($settings->getUiFrameworkOverride());
    }

    public function testHttpHeaderParserRoundTrip(): void
    {
        $headers = HttpHeaderParser::parseLines("X-One: 1\r\n\r\nno-colon\n: empty-name\nX-Two:  a:b  ");

        self::assertSame(['X-One' => '1', 'X-Two' => 'a:b'], $headers);
        self::assertSame("X-One: 1\nX-Two: a:b", HttpHeaderParser::format($headers));
        self::assertSame('', HttpHeaderParser::format([]));
    }

    public function testStatusPageMonitorIds(): void
    {
        self::assertSame([], TenantSettings::from(new Tenant('main', 'Main'))->getStatusPageMonitorIds());
        self::assertSame([], TenantSettings::from((new Tenant('main', 'Main'))->setSettings(['status_page_monitors' => 'broken']))->getStatusPageMonitorIds());
        self::assertSame([3, 7], TenantSettings::from((new Tenant('main', 'Main'))->setSettings(['status_page_monitors' => [3, '7', 'x', null, 1.5]]))->getStatusPageMonitorIds());
    }
}
