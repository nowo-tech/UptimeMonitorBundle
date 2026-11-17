<?php

declare(strict_types=1);

namespace Nowo\UptimeMonitorBundle\Tests\Unit\Ui;

use Nowo\UiKitBundle\View\Action;
use Nowo\UptimeMonitorBundle\Entity\Monitor;
use Nowo\UptimeMonitorBundle\Entity\Tenant;
use Nowo\UptimeMonitorBundle\Enum\MonitorType;
use Nowo\UptimeMonitorBundle\Ui\MonitorHeaderActions;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

use function array_map;

#[CoversClass(MonitorHeaderActions::class)]
final class MonitorHeaderActionsTest extends TestCase
{
    public function testConfiguredActionsBecomeIconButtons(): void
    {
        $monitor = new Monitor(new Tenant('main', 'Main'), 'API', MonitorType::Push, '');
        $actions = [
            new Action('edit', 'Edit', '/edit', attributes: ['data-testid' => 'uptime-monitor-edit']),
            new Action('custom', 'Pause', '/pause', method: 'POST', attributes: ['data-testid' => 'uptime-monitor-pause']),
            Action::delete('/delete', 't'),
            new Action('custom', 'Token', '/token', method: 'POST', attributes: ['data-testid' => 'uptime-push-generate']),
            new Action('custom', 'Revoke', '/revoke', method: 'POST', variant: 'danger', attributes: ['data-testid' => 'uptime-push-revoke']),
            new Action('share', 'Share', '/share', attributes: ['data-testid' => 'host-share']),
        ];

        self::assertSame(['edit', 'pause', 'delete', 'push_token', 'push_revoke', null], array_map(static fn (Action $action): ?string => MonitorHeaderActions::key($action, $monitor), $actions));

        $icons = MonitorHeaderActions::withIcons($actions, $monitor, ['edit' => 'tabler:pencil', 'pause' => 'tabler:player-pause', 'resume' => 'tabler:player-play', 'push_revoke' => 'tabler:key-off']);
        self::assertSame(['icon', 'icon', null, null, 'icon', null], array_map(static fn (Action $action): ?string => $action->display, $icons));
        self::assertSame('tabler:player-pause', $icons[1]->icon);
        self::assertSame($actions[5], $icons[5], 'host actions are left alone');

        $monitor->setPaused(true);
        self::assertSame('resume', MonitorHeaderActions::key($actions[1], $monitor));
        self::assertSame('tabler:player-play', MonitorHeaderActions::withIcons($actions, $monitor, ['resume' => 'tabler:player-play'])[1]->icon);
    }
}
