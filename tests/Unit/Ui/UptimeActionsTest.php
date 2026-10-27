<?php

declare(strict_types=1);

namespace Nowo\UptimeMonitorBundle\Tests\Unit\Ui;

use Nowo\UiKitBundle\View\Action;
use Nowo\UptimeMonitorBundle\Entity\Monitor;
use Nowo\UptimeMonitorBundle\Entity\Tenant;
use Nowo\UptimeMonitorBundle\Enum\MonitorType;
use Nowo\UptimeMonitorBundle\Limits\ConfigurableTenantLimits;
use Nowo\UptimeMonitorBundle\Limits\TenantQuota;
use Nowo\UptimeMonitorBundle\Repository\MonitorRepository;
use Nowo\UptimeMonitorBundle\Security\TenantAwareUptimeMonitorAccessCheckerInterface;
use Nowo\UptimeMonitorBundle\Security\UptimeMonitorAccessCheckerInterface;
use Nowo\UptimeMonitorBundle\Tests\Unit\Support\EntityIdTrait;
use Nowo\UptimeMonitorBundle\Ui\UptimeActions;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use stdClass;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorage;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Security\Core\User\InMemoryUser;
use Symfony\Component\Security\Csrf\CsrfToken;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;

use function array_map;

#[CoversClass(UptimeActions::class)]
final class UptimeActionsTest extends TestCase
{
    use EntityIdTrait;

    /**
     * @param array<string, mixed> $webUi
     * @param array<string, mixed> $statusPage
     */
    private function actions(
        UptimeMonitorAccessCheckerInterface $checker,
        int $maxMonitors = 0,
        int $monitorCount = 0,
        bool $allowUnauthenticated = false,
        array $webUi = [],
        array $statusPage = [],
        bool $withCsrf = true,
    ): UptimeActions {
        $urls = $this->createStub(UrlGeneratorInterface::class);
        $urls->method('generate')->willReturnCallback(
            static fn (string $route, array $params = []): string => '/' . $route . (isset($params['return']) ? '?return=' . $params['return'] : ''),
        );
        $monitors = $this->createStub(MonitorRepository::class);
        $monitors->method('countChecksByTenantSlug')->willReturn($monitorCount);
        $csrf = $this->createStub(CsrfTokenManagerInterface::class);
        $csrf->method('getToken')->willReturnCallback(static fn (string $id): CsrfToken => new CsrfToken($id, 'token-' . $id));
        $tokens = new TokenStorage();
        $tokens->setToken(new UsernamePasswordToken(new InMemoryUser('ana', null), 'main'));

        return new UptimeActions(
            $checker,
            $urls,
            new TenantQuota(new ConfigurableTenantLimits($maxMonitors), $monitors),
            $tokens,
            $withCsrf ? $csrf : null,
            $allowUnauthenticated,
            $webUi,
            $statusPage,
        );
    }

    private function tenantChecker(bool $monitors, bool $settings): TenantAwareUptimeMonitorAccessCheckerInterface
    {
        $checker = $this->createStub(TenantAwareUptimeMonitorAccessCheckerInterface::class);
        $checker->method('canManageTenantMonitors')->willReturn($monitors);
        $checker->method('canManageTenantSettings')->willReturn($settings);

        return $checker;
    }

    private function monitor(bool $paused = false): Monitor
    {
        $monitor = new Monitor(new Tenant('main', 'Main'), 'API', MonitorType::Https, 'https://x.test');
        $monitor->setPaused($paused);
        $this->setEntityId($monitor, 7);

        return $monitor;
    }

    /**
     * @param list<Action> $actions
     *
     * @return list<string>
     */
    private function testIds(array $actions): array
    {
        return array_map(static fn (Action $action): string => (string) ($action->attributes['data-testid'] ?? $action->kind), $actions);
    }

    public function testTenantAwareCheckerDecidesPerTenant(): void
    {
        $actions = $this->actions($this->tenantChecker(true, false));

        self::assertTrue($actions->canManageMonitors('main'));
        self::assertFalse($actions->canManageSettings('main'));
    }

    public function testGlobalCheckerDecidesWithoutTenant(): void
    {
        $checker = $this->createStub(UptimeMonitorAccessCheckerInterface::class);
        $checker->method('canManageMonitors')->willReturn(false);
        $checker->method('canManageSettings')->willReturn(true);
        $actions = $this->actions($checker);

        self::assertFalse($actions->canManageMonitors('main'));
        self::assertTrue($actions->canManageSettings('main'));
    }

    public function testUnauthenticatedModeAllowsEverything(): void
    {
        $actions = $this->actions($this->tenantChecker(false, false), allowUnauthenticated: true);

        self::assertTrue($actions->canManageMonitors('main'));
        self::assertTrue($actions->canManageSettings('main'));
    }

    public function testWithoutTokenStorageTheUserIsAnonymous(): void
    {
        $checker = $this->createMock(TenantAwareUptimeMonitorAccessCheckerInterface::class);
        $checker->expects(self::once())->method('canManageTenantMonitors')
            ->with(self::isInstanceOf(stdClass::class), 'main')
            ->willReturn(false);
        $urls    = $this->createStub(UrlGeneratorInterface::class);
        $actions = new UptimeActions($checker, $urls, new TenantQuota(new ConfigurableTenantLimits(), $this->createStub(MonitorRepository::class)));

        self::assertFalse($actions->canManageMonitors('main'));
    }

    public function testDashboardHeaderFollowsTheConfiguredOrderAndAccess(): void
    {
        $all = $this->actions($this->tenantChecker(true, true));
        self::assertSame(['uptime-dashboard-settings', 'uptime-dashboard-status-page', 'uptime-monitor-new'], $this->testIds($all->dashboardHeader('main')));

        $chosen = $this->actions($this->tenantChecker(true, true), webUi: ['dashboard' => ['actions' => ['new_monitor', 'unknown']]]);
        self::assertSame(['uptime-monitor-new'], $this->testIds($chosen->dashboardHeader('main')));

        $member = $this->actions($this->tenantChecker(false, false), statusPage: ['enabled' => false]);
        self::assertSame([], $member->dashboardHeader('main'));

        $broken = $this->actions($this->tenantChecker(true, true), webUi: ['dashboard' => ['actions' => 'settings']]);
        self::assertSame([], $broken->dashboardHeader('main'));
    }

    public function testNewMonitorOpensTheDialogUntilTheLimit(): void
    {
        $open = $this->actions($this->tenantChecker(true, false), maxMonitors: 2, monitorCount: 1)->newMonitor('main');
        self::assertInstanceOf(Action::class, $open);
        self::assertFalse($open->disabled);
        self::assertTrue($open->attributes['data-monitor-form-open']);

        $full = $this->actions($this->tenantChecker(true, false), maxMonitors: 2, monitorCount: 2)->newMonitor('main');
        self::assertInstanceOf(Action::class, $full);
        self::assertTrue($full->disabled);
        self::assertArrayNotHasKey('data-monitor-form-open', $full->attributes);

        self::assertNull($this->actions($this->tenantChecker(false, false))->newMonitor('main'));
    }

    public function testMonitorActionsForManagers(): void
    {
        $actions = $this->actions($this->tenantChecker(true, false));

        $page = $actions->monitor('main', $this->monitor());
        self::assertSame(['uptime-monitor-edit', 'uptime-monitor-pause', 'delete'], $this->testIds($page));
        self::assertSame('/nowo_uptime_monitor_toggle_pause?return=show', $page[1]->href);
        self::assertSame('custom', $page[1]->kind);
        self::assertSame('token-toggle7', $page[1]->csrfToken);
        self::assertSame('token-delete7', $page[2]->csrfToken);
        self::assertSame('show', $page[0]->attributes['data-monitor-form-return']);

        $row = $actions->monitor('main', $this->monitor(true), true);
        self::assertSame(['view', 'uptime-monitor-edit', 'uptime-monitor-pause', 'delete'], $this->testIds($row));
        self::assertSame('/nowo_uptime_monitor_toggle_pause', $row[2]->href);
        self::assertSame('restore', $row[2]->kind);
        self::assertSame('dashboard', $row[1]->attributes['data-monitor-form-return']);
    }

    public function testMonitorActionsForReaders(): void
    {
        $actions = $this->actions($this->tenantChecker(false, false), withCsrf: false);

        self::assertSame([], $actions->monitor('main', $this->monitor()));
        self::assertSame(['view'], $this->testIds($actions->monitor('main', $this->monitor(), true)));
    }

    public function testPostActionsWithoutCsrfManagerCarryNoToken(): void
    {
        $page = $this->actions($this->tenantChecker(true, false), withCsrf: false)->monitor('main', $this->monitor());

        self::assertNull($page[1]->csrfToken);
    }
}
