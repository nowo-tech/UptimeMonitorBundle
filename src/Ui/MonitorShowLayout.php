<?php

declare(strict_types=1);

namespace Nowo\UptimeMonitorBundle\Ui;

use Nowo\UptimeMonitorBundle\DependencyInjection\Configuration;

use function in_array;
use function is_array;
use function is_int;
use function is_string;

/**
 * The monitor page layout (`web_ui.monitor_show`) as the template reads it (variable `monitor_layout`): chart mode and
 * slots (1.21); region order, check log options and header options (1.30). Unknown or missing values fall back to the
 * defaults, which render the 1.29 page. Pure function of the processed configuration (no state).
 */
final class MonitorShowLayout
{
    /** Default check log time format (the 1.29 one). */
    public const TIME_FORMAT = 'Y-m-d H:i:s';

    /**
     * Resolves the processed `web_ui.monitor_show` configuration. `order` is the full region list (the configured
     * regions, then the missing ones of {@see Configuration::MONITOR_SHOW_REGIONS} in that order; `details` only when
     * configured), or [] for the bundle layout.
     *
     * @param array<string, mixed> $options
     *
     * @return array{charts: string, slots: array<string, string|null>, order: list<string>, log: array{sortable: bool, align_numbers: bool, time_format: string, time_title_format: string|null, per_page: int|null}, header: array{actions_layout: string, status: bool, icons: array<string, string>}}
     */
    public static function resolve(array $options): array
    {
        $charts = $options['charts'] ?? 'bundle';
        $slots  = $options['slots'] ?? [];
        $log    = is_array($options['log'] ?? null) ? $options['log'] : [];
        $header = is_array($options['header'] ?? null) ? $options['header'] : [];

        return [
            'charts' => in_array($charts, ['host', 'none'], true) ? $charts : 'bundle',
            'slots'  => is_array($slots) ? $slots : [],
            'order'  => self::order($options['order'] ?? []),
            'log'    => [
                'sortable'          => ($log['sortable'] ?? false) === true,
                'align_numbers'     => ($log['align_numbers'] ?? false) === true,
                'time_format'       => is_string($log['time_format'] ?? null) && $log['time_format'] !== '' ? $log['time_format'] : self::TIME_FORMAT,
                'time_title_format' => is_string($log['time_title_format'] ?? null) && $log['time_title_format'] !== '' ? $log['time_title_format'] : null,
                'per_page'          => is_int($log['per_page'] ?? null) && $log['per_page'] > 0 ? $log['per_page'] : null,
            ],
            'header' => [
                'actions_layout' => ($header['actions_layout'] ?? 'toolbar') === 'split' ? 'split' : 'toolbar',
                'status'         => ($header['status'] ?? false) === true,
                'icons'          => self::icons($header['icons'] ?? []),
            ],
        ];
    }

    /**
     * @return list<string>
     */
    private static function order(mixed $configured): array
    {
        if (!is_array($configured) || $configured === []) {
            return [];
        }
        $order = [];
        foreach ($configured as $region) {
            if (is_string($region) && !in_array($region, $order, true) && ($region === 'details' || in_array($region, Configuration::MONITOR_SHOW_REGIONS, true))) {
                $order[] = $region;
            }
        }
        foreach (Configuration::MONITOR_SHOW_REGIONS as $region) {
            if (!in_array($region, $order, true)) {
                $order[] = $region;
            }
        }

        return $order;
    }

    /**
     * @return array<string, string>
     */
    private static function icons(mixed $configured): array
    {
        $icons = [];
        foreach (is_array($configured) ? $configured : [] as $action => $icon) {
            if (is_string($action) && in_array($action, Configuration::MONITOR_SHOW_ICON_ACTIONS, true) && is_string($icon) && $icon !== '') {
                $icons[$action] = $icon;
            }
        }

        return $icons;
    }
}
