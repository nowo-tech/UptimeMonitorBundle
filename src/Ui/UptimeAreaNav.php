<?php

declare(strict_types=1);

namespace Nowo\UptimeMonitorBundle\Ui;

use Nowo\UiKitBundle\Navigation\AreaNavProviderInterface;
use Nowo\UiKitBundle\View\AreaNav;
use Nowo\UiKitBundle\View\AreaNavItem;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

use function in_array;
use function is_string;

/**
 * Publishes the bundle navigation to the UiKit area-nav registry (REQ-UI-003 "Navigation integration"), so a host
 * menu (DashboardMenu) can mount it: `nowo_ui_area_nav('uptime_monitor', {tenantSlug: 'main'})` (Dashboard | Settings |
 * Status page of a tenant) and `nowo_ui_area_nav('uptime_monitor_settings', {tenantSlug: 'main'})` (the settings
 * screens, the same entries as the settings page tabs). Both need the `tenantSlug` context; `current` (an href)
 * marks the active item. Stateless: safe in worker mode.
 */
final readonly class UptimeAreaNav implements AreaNavProviderInterface
{
    public const AREA = 'uptime_monitor';

    public const AREA_SETTINGS = 'uptime_monitor_settings';

    /** Settings screens (section id => route), in tab order. */
    public const SETTINGS_SECTIONS = [
        'general'       => 'nowo_uptime_settings_general',
        'appearance'    => 'nowo_uptime_settings_appearance',
        'notifications' => 'nowo_uptime_settings_notifications',
        'reverse_proxy' => 'nowo_uptime_settings_reverse_proxy',
        'status_page'   => 'nowo_uptime_settings_status_page',
        'tags'          => 'nowo_uptime_settings_tags',
        'history'       => 'nowo_uptime_settings_history',
        'backup'        => 'nowo_uptime_settings_backup',
        'about'         => 'nowo_uptime_settings_about',
    ];

    /**
     * Tab icons (UiKit 2.17 `icon`, Tabler names; shown when the host's tab style asks for icons): settings sections
     * by id, then the area tabs.
     */
    public const ICONS = [
        'general'       => 'tabler:adjustments',
        'appearance'    => 'tabler:palette',
        'notifications' => 'tabler:bell',
        'reverse_proxy' => 'tabler:server',
        'status_page'   => 'tabler:broadcast',
        'tags'          => 'tabler:tags',
        'history'       => 'tabler:history',
        'backup'        => 'tabler:database-export',
        'about'         => 'tabler:info-circle',
        'dashboard'     => 'tabler:activity',
        'settings'      => 'tabler:settings',
    ];

    /**
     * Creates the navigation provider.
     *
     * @param UrlGeneratorInterface $urlGenerator Builds the navigation links
     * @param SettingsSections $sections The settings screens the host keeps (since 1.13)
     */
    public function __construct(
        private UrlGeneratorInterface $urlGenerator,
        private SettingsSections $sections = new SettingsSections(),
    ) {
    }

    /**
     * @param array<string, mixed> $context `tenantSlug` (required), `current` (href of the active item)
     */
    public function areaNav(string $area, array $context = []): ?AreaNav
    {
        $tenantSlug = $context['tenantSlug'] ?? null;
        if (!is_string($tenantSlug) || $tenantSlug === '' || !in_array($area, [self::AREA, self::AREA_SETTINGS], true)) {
            return null;
        }

        $params = ['tenantSlug' => $tenantSlug];
        if ($area === self::AREA_SETTINGS) {
            $items = [];
            foreach ($this->sections->all() as $id => $route) {
                $items[] = new AreaNavItem(UptimePages::label('settings.nav.' . $id), $this->urlGenerator->generate($route, $params), false, ['data-testid' => 'uptime-nav-settings-' . $id], self::ICONS[$id] ?? null);
            }

            return new AreaNav($items, UptimePages::label('settings.title'));
        }

        return new AreaNav([
            new AreaNavItem(UptimePages::label('nav.dashboard'), $this->urlGenerator->generate('nowo_uptime_dashboard', $params), false, ['data-testid' => 'uptime-nav-dashboard'], self::ICONS['dashboard']),
            new AreaNavItem(UptimePages::label('dashboard.settings'), $this->urlGenerator->generate('nowo_uptime_settings_index', $params), false, ['data-testid' => 'uptime-nav-settings'], self::ICONS['settings']),
            new AreaNavItem(UptimePages::label('dashboard.status_page'), $this->urlGenerator->generate('nowo_uptime_status_page', $params), false, ['data-testid' => 'uptime-nav-status-page'], self::ICONS['status_page']),
        ], UptimePages::label('app.title'));
    }
}
