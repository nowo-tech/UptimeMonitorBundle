<?php

declare(strict_types=1);

namespace Nowo\UptimeMonitorBundle\Tests\Unit\Controller;

use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Nowo\UiKitBundle\View\Action;
use Nowo\UiKitBundle\View\DashboardPage;
use Nowo\UiKitBundle\View\DetailItem;
use Nowo\UiKitBundle\View\EmptyState;
use Nowo\UiKitBundle\View\Label;
use Nowo\UiKitBundle\View\Pagination;
use Nowo\UiKitBundle\View\ShowPage;
use Nowo\UptimeMonitorBundle\Controller\DashboardController;
use Nowo\UptimeMonitorBundle\Controller\MonitorController;
use Nowo\UptimeMonitorBundle\Controller\StatusPageController;
use Nowo\UptimeMonitorBundle\Entity\CheckResult;
use Nowo\UptimeMonitorBundle\Entity\Monitor;
use Nowo\UptimeMonitorBundle\Entity\Tenant;
use Nowo\UptimeMonitorBundle\Enum\CheckStatus;
use Nowo\UptimeMonitorBundle\Enum\MonitorType;
use Nowo\UptimeMonitorBundle\Event\StatusPageMonitorsEvent;
use Nowo\UptimeMonitorBundle\Event\UptimePageEvent;
use Nowo\UptimeMonitorBundle\Form\MonitorFormType;
use Nowo\UptimeMonitorBundle\Limits\ConfigurableTenantLimits;
use Nowo\UptimeMonitorBundle\Limits\TenantQuota;
use Nowo\UptimeMonitorBundle\Repository\CheckAggregateRepository;
use Nowo\UptimeMonitorBundle\Repository\CheckResultRepository;
use Nowo\UptimeMonitorBundle\Repository\MonitorRepository;
use Nowo\UptimeMonitorBundle\Repository\TenantRepository;
use Nowo\UptimeMonitorBundle\Security\TenantAwareUptimeMonitorAccessCheckerInterface;
use Nowo\UptimeMonitorBundle\Service\AggregateChartService;
use Nowo\UptimeMonitorBundle\Tests\Unit\Support\ControllerContainerTrait;
use Nowo\UptimeMonitorBundle\Tests\Unit\Support\EntityIdTrait;
use Nowo\UptimeMonitorBundle\Tests\Unit\Support\SyncDispatcherTestTrait;
use Nowo\UptimeMonitorBundle\Ui\UptimeActions;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\Form\FormView;
use Symfony\Component\Form\Util\StringUtil;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

use function array_map;

/**
 * 1.12 host embedding: page actions by access, the host page event, the paginated check log, the table dashboard,
 * the tenant limits on the monitor form and the status page monitors event.
 */
#[CoversClass(MonitorController::class)]
#[CoversClass(DashboardController::class)]
#[CoversClass(StatusPageController::class)]
final class HostEmbeddingControllersTest extends TestCase
{
    use ControllerContainerTrait;
    use EntityIdTrait;
    use SyncDispatcherTestTrait;

    private function actions(bool $manage, int $maxMonitors = 0, int $count = 0): UptimeActions
    {
        $checker = $this->createStub(TenantAwareUptimeMonitorAccessCheckerInterface::class);
        $checker->method('canManageTenantMonitors')->willReturn($manage);
        $checker->method('canManageTenantSettings')->willReturn($manage);
        $urls = $this->createStub(UrlGeneratorInterface::class);
        $urls->method('generate')->willReturnCallback(static fn (string $route): string => '/' . $route);

        return new UptimeActions($checker, $urls, $this->quota($maxMonitors, $count), allowUnauthenticated: false, webUi: ['dashboard' => ['actions' => ['new_monitor']]]);
    }

    private function quota(int $max, int $count, int $minInterval = 60): TenantQuota
    {
        $monitors = $this->createStub(MonitorRepository::class);
        $monitors->method('countChecksByTenantSlug')->willReturn($count);

        return new TenantQuota(new ConfigurableTenantLimits($max, $minInterval), $monitors);
    }

    private function dispatcher(): EventDispatcher
    {
        $dispatcher = new EventDispatcher();
        $dispatcher->addListener(UptimePageEvent::class, static function (UptimePageEvent $event): void {
            $event->addAction(new Action('share', 'Share', '/share', attributes: ['data-testid' => 'host-share']));
            $event->addSection('host/_note.html.twig', ['page' => $event->page], 'before');
        });

        return $dispatcher;
    }

    /**
     * @param array<string, mixed> $webUi
     * @param list<string> $hiddenFields
     */
    private function monitorController(
        Monitor $monitor,
        CheckResultRepository $checks,
        ?UptimeActions $actions,
        ?TenantQuota $quota = null,
        array $webUi = [],
        array $hiddenFields = [],
        ?EntityManagerInterface $em = null,
    ): MonitorController {
        $tenantRepo = $this->createStub(TenantRepository::class);
        $tenantRepo->method('findOneBySlug')->willReturn($monitor->getTenant());
        $monitorRepo = $this->createStub(MonitorRepository::class);
        $monitorRepo->method('find')->willReturn($monitor);
        $aggregates = $this->createStub(CheckAggregateRepository::class);
        $aggregates->method('findForMonitorInRange')->willReturn([]);

        return new MonitorController(
            $this->createStub(TranslatorInterface::class),
            $tenantRepo,
            $monitorRepo,
            $checks,
            $this->monitorFactory($monitorRepo),
            new AggregateChartService($aggregates),
            $this->dashboardViewBuilder($monitorRepo, $checks),
            $em ?? $this->createStub(EntityManagerInterface::class),
            actions: $actions,
            quota: $quota,
            eventDispatcher: $this->dispatcher(),
            webUi: $webUi,
            hiddenFields: $hiddenFields,
        );
    }

    private function monitor(): Monitor
    {
        $monitor = new Monitor(new Tenant('main', 'Main'), 'API', MonitorType::Https, 'https://x.test');
        $this->setEntityId($monitor, 4);

        return $monitor;
    }

    /**
     * @return list<CheckResult>
     */
    private function checks(Monitor $monitor, int $count): array
    {
        $checks = [];
        for ($i = 0; $i < $count; ++$i) {
            $checks[] = new CheckResult($monitor, CheckStatus::Up, 10 + $i);
        }

        return $checks;
    }

    public function testMonitorPageCarriesTheAllowedActionsTheHostAndOneLogPage(): void
    {
        $monitor = $this->monitor();
        $checks  = $this->createStub(CheckResultRepository::class);
        $checks->method('findRecentForMonitor')->willReturn($this->checks($monitor, 5));
        $controller = $this->monitorController($monitor, $checks, $this->actions(true), webUi: ['per_page' => 2], hiddenFields: ['project']);
        $this->bindController($controller);

        $controller->show('main', 4, Request::create('/main/monitors/4', 'GET', ['page' => 3]));

        $page = $this->renderedContext['page'];
        self::assertInstanceOf(ShowPage::class, $page);
        self::assertSame(['uptime-monitor-edit', 'uptime-monitor-pause', 'delete', 'host-share'], array_map(
            static fn (Action $action): string => (string) ($action->attributes['data-testid'] ?? $action->kind),
            $page->header->actions,
        ));
        $labels = array_map(static fn (DetailItem $item): string => $item->label instanceof Label ? $item->label->key : $item->label, $page->sections[0]->items);
        self::assertNotContains('monitor.show.project', $labels);
        self::assertSame([['template' => 'host/_note.html.twig', 'context' => ['page' => 'monitor_show']]], $this->renderedContext['page_sections']['before']);
        self::assertSame([], $this->renderedContext['page_sections']['after']);
        $pager = $this->renderedContext['log_pagination'];
        self::assertInstanceOf(Pagination::class, $pager);
        self::assertSame(3, $pager->page);
        self::assertSame(3, $pager->totalPages);
        self::assertCount(1, $this->renderedContext['log_events']);
    }

    public function testMonitorPageWithoutActionsServiceKeepsEditAndTheProjectRow(): void
    {
        $monitor = $this->monitor();
        $checks  = $this->createStub(CheckResultRepository::class);
        $checks->method('findRecentForMonitor')->willReturn([]);
        $controller = $this->monitorController($monitor, $checks, null, webUi: ['per_page' => 'x']);
        $this->bindController($controller);

        $controller->show('main', 4);

        $page = $this->renderedContext['page'];
        self::assertInstanceOf(ShowPage::class, $page);
        self::assertSame('uptime-monitor-edit', $page->header->actions[0]->attributes['data-testid']);
        $labels = array_map(static fn (DetailItem $item): string => $item->label instanceof Label ? $item->label->key : $item->label, $page->sections[0]->items);
        self::assertContains('monitor.show.project', $labels);
        self::assertSame(20, $this->renderedContext['log_pagination']->perPage);
    }

    public function testNewMonitorIsRefusedOnceTheTenantIsFull(): void
    {
        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects(self::never())->method('persist');
        $monitor    = $this->monitor();
        $controller = $this->monitorController($monitor, $this->createStub(CheckResultRepository::class), $this->actions(true), $this->quota(1, 1), em: $em);
        $request    = $this->post('/main/monitors/new', '60');
        $this->bindController($controller, true, $request);

        $response = $controller->new('main', $request);

        self::assertSame('rendered', $response->getContent());
    }

    public function testTheFormCheckerUsesTheTenantMinimumInterval(): void
    {
        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects(self::never())->method('flush');
        $monitor    = $this->monitor();
        $controller = $this->monitorController($monitor, $this->createStub(CheckResultRepository::class), $this->actions(true), $this->quota(0, 0, 120), em: $em);
        $request    = $this->post('/main/monitors/4/edit', '60');
        $this->bindController($controller, true, $request);

        $controller->edit('main', 4, $request);

        $form = $this->renderedContext['form'];
        self::assertInstanceOf(FormView::class, $form);
        self::assertSame(120, $form->children['intervalSeconds']->vars['attr']['min']);
        self::assertFalse($form->children['intervalSeconds']->vars['valid']);
    }

    private function post(string $path, string $interval): Request
    {
        return Request::create($path, 'POST', [StringUtil::fqcnToBlockPrefix(MonitorFormType::class) => [
            'type'                        => 'https',
            'name'                        => 'API',
            'parentId'                    => '',
            'project'                     => '',
            'intervalSeconds'             => $interval,
            'retries'                     => '0',
            'retryIntervalSeconds'        => '60',
            'requestTimeoutSeconds'       => '10',
            'resendNotificationAfterDown' => '0',
            'url'                         => 'https://api.example.test/health',
            'method'                      => 'GET',
            'expectedStatusCodes'         => '200-299',
            'maxRedirects'                => '10',
            'bodyEncoding'                => 'json',
            'authMethod'                  => 'none',
            'port'                        => '443',
            'recordType'                  => 'A',
            'daysBeforeExpiry'            => '14',
            '_token'                      => 'valid',
        ]]);
    }

    public function testTogglePauseReturnsToTheMonitorPage(): void
    {
        $monitor    = $this->monitor();
        $controller = $this->monitorController($monitor, $this->createStub(CheckResultRepository::class), $this->actions(true));
        $request    = Request::create('/main/monitors/4/toggle-pause?return=show', 'POST', ['_token' => 'valid']);
        $this->bindController($controller, true, $request);

        $response = $controller->togglePause('main', 4, $request);

        self::assertSame('/generated/nowo_uptime_monitor_show', $response->getTargetUrl());
        self::assertTrue($monitor->isPaused());
    }

    /**
     * @param list<Monitor> $monitors
     * @param array<string, mixed> $webUi
     */
    private function dashboardController(array $monitors, CheckResultRepository $checks, ?UptimeActions $actions, array $webUi): DashboardController
    {
        $tenantRepo = $this->createStub(TenantRepository::class);
        $tenantRepo->method('findOneBySlug')->willReturn(new Tenant('main', 'Main'));
        $monitorRepo = $this->createStub(MonitorRepository::class);
        $monitorRepo->method('findByTenantSlug')->willReturn($monitors);
        $monitorRepo->method('findGroupsByTenantSlug')->willReturn([]);
        $aggregates = $this->createStub(CheckAggregateRepository::class);
        $aggregates->method('findTenantOverview')->willReturn([]);

        return new DashboardController(
            $tenantRepo,
            $monitorRepo,
            $this->dashboardViewBuilder($monitorRepo, $checks),
            new AggregateChartService($aggregates),
            ['poll_interval_ms' => 5000, 'sync' => 'polling'],
            $this->pollingSyncDispatcher(),
            actions: $actions,
            eventDispatcher: $this->dispatcher(),
            webUi: $webUi,
        );
    }

    public function testTableDashboardListsTheMonitorsAndOnePageOfChecks(): void
    {
        $tenant = new Tenant('main', 'Main');
        $api    = new Monitor($tenant, 'API', MonitorType::Https, 'https://user:secret@x.test');
        $this->setEntityId($api, 1);
        $older  = new CheckResult($api, CheckStatus::Up, 10, checkedAt: new DateTimeImmutable('-2 minutes'));
        $newer  = new CheckResult($api, CheckStatus::Down, 12, checkedAt: new DateTimeImmutable('-1 minute'));
        $checks = $this->createStub(CheckResultRepository::class);
        $checks->method('findLatestByMonitorIds')->willReturn([1 => $newer]);
        $checks->method('findRecentForTenant')->willReturn([$older, $newer]);
        $controller = $this->dashboardController([$api], $checks, $this->actions(true), ['dashboard' => ['view' => 'table', 'header' => 'monitors'], 'per_page' => 5]);
        $this->bindController($controller);

        $controller->index('main', new Request());

        self::assertSame('table', $this->renderedContext['dashboard_view']);
        self::assertSame($api, $this->renderedContext['monitor_rows'][0]['monitor']);
        self::assertCount(4, $this->renderedContext['monitor_row_actions'][1]);
        self::assertStringNotContainsString('secret', $this->renderedContext['monitor_targets'][1]);
        self::assertInstanceOf(EmptyState::class, $this->renderedContext['dashboard_empty']);
        self::assertSame('down', $this->renderedContext['events_page'][0]['status']);
        self::assertSame(1, $this->renderedContext['events_pagination']->totalPages);
        $page = $this->renderedContext['page'];
        self::assertInstanceOf(DashboardPage::class, $page);
        self::assertSame('monitors', $this->renderedContext['dashboard_header']);
        self::assertInstanceOf(Label::class, $page->header->title);
        self::assertSame('dashboard.table.title', $page->header->title->key);
        self::assertNull($page->header->intro);
        self::assertSame(['uptime-monitor-new', 'host-share'], array_map(static fn (Action $action): string => (string) $action->attributes['data-testid'], $page->header->actions));
    }

    public function testTreeDashboardWithoutActionsServiceKeepsTheThreeActions(): void
    {
        $checks = $this->createStub(CheckResultRepository::class);
        $checks->method('findLatestByMonitorIds')->willReturn([]);
        $controller = $this->dashboardController([], $checks, null, ['per_page' => 'x']);
        $this->bindController($controller);

        $controller->index('main', new Request());

        self::assertSame('tree', $this->renderedContext['dashboard_view']);
        self::assertSame('tenant', $this->renderedContext['dashboard_header']);
        self::assertSame('Main', $this->renderedContext['page']->header->title);
        self::assertArrayNotHasKey('monitor_rows', $this->renderedContext);
        self::assertCount(4, $this->renderedContext['page']->header->actions);
    }

    public function testTableDashboardWithoutActionsServiceHasNoRowActions(): void
    {
        $tenant = new Tenant('main', 'Main');
        $api    = new Monitor($tenant, 'API', MonitorType::Https, 'https://x.test');
        $this->setEntityId($api, 1);
        $checks = $this->createStub(CheckResultRepository::class);
        $checks->method('findLatestByMonitorIds')->willReturn([]);
        $controller = $this->dashboardController([$api], $checks, null, ['dashboard' => ['view' => 'table'], 'per_page' => 'x']);
        $this->bindController($controller);

        $controller->index('main', new Request());

        self::assertSame([], $this->renderedContext['monitor_row_actions'][1]);
        self::assertSame(20, $this->renderedContext['events_pagination']->perPage);
        self::assertNull($this->renderedContext['dashboard_empty']->action);
    }

    public function testStatusPageShowsTheMonitorsTheHostKeeps(): void
    {
        $tenant = new Tenant('main', 'Main');
        $api    = new Monitor($tenant, 'API', MonitorType::Https, 'https://x.test');
        $web    = new Monitor($tenant, 'Web', MonitorType::Https, 'https://y.test');
        $this->setEntityId($api, 1);
        $this->setEntityId($web, 2);
        $tenantRepo = $this->createStub(TenantRepository::class);
        $tenantRepo->method('findOneBySlug')->willReturn($tenant);
        $monitorRepo = $this->createStub(MonitorRepository::class);
        $monitorRepo->method('findByTenantSlug')->willReturn([$api, $web]);
        $checks = $this->createStub(CheckResultRepository::class);
        $checks->method('findLatestByMonitorIds')->willReturn([2 => new CheckResult($web, CheckStatus::Down, 10)]);
        $dispatcher = new EventDispatcher();
        $dispatcher->addListener(StatusPageMonitorsEvent::class, static function (StatusPageMonitorsEvent $event) use ($api): void {
            $event->setMonitors([$api]);
        });
        $controller = new StatusPageController($tenantRepo, $monitorRepo, $checks, ['enabled' => true], eventDispatcher: $dispatcher);
        $this->bindController($controller);

        $controller->index('main');

        self::assertCount(1, $this->renderedContext['rows']);
        self::assertSame($api, $this->renderedContext['rows'][0]['monitor']);
        self::assertSame('unknown', $this->renderedContext['overall_status']);
    }
}
