<?php

declare(strict_types=1);

namespace Nowo\UptimeMonitorBundle\Tests\Unit\Ui;

use Nowo\UiKitBundle\View\AreaNav;
use Nowo\UiKitBundle\View\Label;
use Nowo\UptimeMonitorBundle\Ui\SettingsSections;
use Nowo\UptimeMonitorBundle\Ui\UptimeAreaNav;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

use function count;
use function http_build_query;

#[CoversClass(UptimeAreaNav::class)]
final class UptimeAreaNavTest extends TestCase
{
    public function testTenantArea(): void
    {
        $nav = $this->provider()->areaNav(UptimeAreaNav::AREA, ['tenantSlug' => 'main']);

        self::assertInstanceOf(AreaNav::class, $nav);
        self::assertCount(3, $nav->items);
        self::assertSame('/nowo_uptime_dashboard?tenantSlug=main', $nav->items[0]->href);
        self::assertSame('/nowo_uptime_settings_index?tenantSlug=main', $nav->items[1]->href);
        self::assertSame('/nowo_uptime_status_page?tenantSlug=main', $nav->items[2]->href);
        self::assertInstanceOf(Label::class, $nav->items[0]->label);
        self::assertSame('nav.dashboard', $nav->items[0]->label->key);
        self::assertSame('NowoUptimeMonitorBundle', $nav->items[0]->label->domain);
        self::assertSame(['data-testid' => 'uptime-nav-dashboard'], $nav->items[0]->attributes);
    }

    public function testSettingsArea(): void
    {
        $nav = $this->provider()->areaNav(UptimeAreaNav::AREA_SETTINGS, ['tenantSlug' => 'acme']);

        self::assertInstanceOf(AreaNav::class, $nav);
        self::assertCount(count(UptimeAreaNav::SETTINGS_SECTIONS), $nav->items);
        self::assertSame('/nowo_uptime_settings_general?tenantSlug=acme', $nav->items[0]->href);
        self::assertSame('/nowo_uptime_settings_about?tenantSlug=acme', $nav->items[8]->href);
    }

    public function testSettingsAreaShowsTheKeptScreens(): void
    {
        $router = $this->createStub(UrlGeneratorInterface::class);
        $router->method('generate')->willReturnCallback(static fn (string $route): string => '/' . $route);
        $nav = (new UptimeAreaNav($router, new SettingsSections(['settings' => ['sections' => ['history', 'tags']]])))->areaNav(UptimeAreaNav::AREA_SETTINGS, ['tenantSlug' => 'acme']);

        self::assertInstanceOf(AreaNav::class, $nav);
        self::assertSame(['/nowo_uptime_settings_tags', '/nowo_uptime_settings_history'], [$nav->items[0]->href, $nav->items[1]->href]);
        self::assertCount(2, $nav->items);
    }

    public function testUnknownAreaOrMissingTenant(): void
    {
        $provider = $this->provider();

        self::assertNull($provider->areaNav('other', ['tenantSlug' => 'main']));
        self::assertNull($provider->areaNav(UptimeAreaNav::AREA));
        self::assertNull($provider->areaNav(UptimeAreaNav::AREA, ['tenantSlug' => '']));
    }

    private function provider(): UptimeAreaNav
    {
        $router = $this->createMock(UrlGeneratorInterface::class);
        $router->method('generate')->willReturnCallback(
            static fn (string $route, array $params = []): string => '/' . $route . '?' . http_build_query($params),
        );

        return new UptimeAreaNav($router);
    }
}
