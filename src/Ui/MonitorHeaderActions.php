<?php

declare(strict_types=1);

namespace Nowo\UptimeMonitorBundle\Ui;

use Nowo\UiKitBundle\View\Action;
use Nowo\UptimeMonitorBundle\Entity\Monitor;

/**
 * Icon buttons of the monitor page header (since 1.30, `web_ui.monitor_show.header.icons`): the bundle actions named
 * in the map become icon-only buttons (UiKit `Action::asIcon()`; the label stays the accessible name), which the
 * `split` header layout shows as square buttons. Host actions (UptimePageEvent) are left as the host built them.
 */
final class MonitorHeaderActions
{
    /**
     * The actions with the configured icons applied.
     *
     * @param list<Action> $actions Bundle header actions of the monitor page
     * @param array<string, string> $icons Icon by action key (edit, pause, resume, delete, push_token, push_revoke)
     *
     * @return list<Action>
     */
    public static function withIcons(array $actions, Monitor $monitor, array $icons): array
    {
        $result = [];
        foreach ($actions as $action) {
            $key      = self::key($action, $monitor);
            $result[] = $key !== null && isset($icons[$key]) ? $action->asIcon($icons[$key]) : $action;
        }

        return $result;
    }

    /**
     * The configuration key of a bundle action (null for any other action).
     */
    public static function key(Action $action, Monitor $monitor): ?string
    {
        $testId = $action->attributes['data-testid'] ?? null;

        return match (true) {
            $action->kind === 'edit'           => 'edit',
            $action->kind === 'delete'         => 'delete',
            $testId === 'uptime-monitor-pause' => $monitor->isPaused() ? 'resume' : 'pause',
            $testId === 'uptime-push-generate' => 'push_token',
            $testId === 'uptime-push-revoke'   => 'push_revoke',
            default                            => null,
        };
    }
}
