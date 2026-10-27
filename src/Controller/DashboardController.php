<?php

declare(strict_types=1);

namespace Nowo\UptimeMonitorBundle\Controller;

use Nowo\UiKitBundle\View\Action;
use Nowo\UiKitBundle\View\DashboardPage;
use Nowo\UiKitBundle\View\EmptyState;
use Nowo\UiKitBundle\View\PageHeader;
use Nowo\UiKitBundle\View\Pagination;
use Nowo\UptimeMonitorBundle\Entity\Monitor;
use Nowo\UptimeMonitorBundle\Entity\Tenant;
use Nowo\UptimeMonitorBundle\Event\UptimePageEvent;
use Nowo\UptimeMonitorBundle\Form\MonitorSearchFormType;
use Nowo\UptimeMonitorBundle\Monitor\TenantSettings;
use Nowo\UptimeMonitorBundle\Repository\MonitorRepository;
use Nowo\UptimeMonitorBundle\Repository\TenantRepository;
use Nowo\UptimeMonitorBundle\Security\SecretRedactor;
use Nowo\UptimeMonitorBundle\Service\AggregateChartService;
use Nowo\UptimeMonitorBundle\Service\DashboardSyncDispatcher;
use Nowo\UptimeMonitorBundle\Service\DashboardViewBuilder;
use Nowo\UptimeMonitorBundle\Ui\UptimeActions;
use Nowo\UptimeMonitorBundle\Ui\UptimePages;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Mercure\Authorization;
use Symfony\Component\Mercure\HubInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

use function array_slice;
use function count;
use function is_int;
use function sprintf;
use function strcmp;
use function strtolower;
use function usort;

use const JSON_THROW_ON_ERROR;

/**
 * Twig shell for the uptime dashboard (server-rendered list + polling).
 */
final class DashboardController extends AbstractController
{
    /**
     * @param array<string, mixed> $dashboardConfig
     * @param UptimeActions|null $actions Header and row actions for the users allowed to use them (since 1.12)
     * @param EventDispatcherInterface|null $eventDispatcher Dispatches {@see UptimePageEvent} (since 1.12)
     * @param array<string, mixed> $webUi Processed `web_ui` config (`dashboard.view`, `per_page`)
     */
    public function __construct(
        private readonly TenantRepository $tenantRepository,
        private readonly MonitorRepository $monitorRepository,
        private readonly DashboardViewBuilder $dashboardViewBuilder,
        private readonly AggregateChartService $chartService,
        #[Autowire('%nowo_uptime_monitor.dashboard%')]
        private readonly array $dashboardConfig,
        private readonly DashboardSyncDispatcher $dashboardSyncDispatcher,
        #[Autowire('@?mercure.hub.default')]
        private readonly ?HubInterface $mercureHub = null,
        #[Autowire('@?Symfony\Component\Mercure\Authorization')]
        private readonly ?Authorization $mercureAuthorization = null,
        private readonly UptimePages $pages = new UptimePages(),
        private readonly ?UptimeActions $actions = null,
        private readonly ?EventDispatcherInterface $eventDispatcher = null,
        #[Autowire('%nowo_uptime_monitor.web_ui%')]
        private readonly array $webUi = [],
    ) {
    }

    /**
     * Renders the tenant dashboard (monitor tree, quick stats, event log) and, with Mercure sync, sets the subscriber
     * cookie.
     *
     * @param string $tenantSlug Tenant slug from the route
     * @param Request $request Current request (`monitor` query filters the event log)
     *
     * @throws NotFoundHttpException When the tenant does not exist
     */
    #[Route(
        path: '/{tenantSlug}',
        name: 'nowo_uptime_dashboard',
        requirements: ['tenantSlug' => '(?!tenants$)[a-z0-9\-]+'],
        priority: -1,
    )]
    public function index(string $tenantSlug, Request $request): Response
    {
        $tenant = $this->tenantRepository->findOneBySlug($tenantSlug);
        if (!$tenant instanceof Tenant) {
            throw $this->createNotFoundException(sprintf('Tenant "%s" not found.', $tenantSlug));
        }

        $eventsFilterMonitor = $this->resolveEventsFilterMonitor($tenantSlug, $request);
        $groups              = $this->dashboardViewBuilder->buildProjectGroups($tenantSlug);
        $stats               = $this->dashboardViewBuilder->buildQuickStats($tenantSlug);
        $events              = $this->dashboardViewBuilder->buildRecentEvents(
            $tenantSlug,
            $eventsFilterMonitor?->getId(),
        );
        $overview      = $this->chartService->buildTenantOverview($tenantSlug, 7);
        $sync          = (string) ($this->dashboardConfig['sync'] ?? 'polling');
        $mercureHubUrl = null;
        $mercureTopic  = null;

        if ($sync === 'mercure') {
            $mercureTopic = $this->dashboardSyncDispatcher->resolveTopic($tenantSlug);
            if ($this->mercureHub instanceof HubInterface) {
                $mercureHubUrl = $this->mercureHub->getPublicUrl();
            }
        }

        $tenantSettings = TenantSettings::from($tenant);
        $pageEvent      = new UptimePageEvent('dashboard', $tenantSlug);
        $this->eventDispatcher?->dispatch($pageEvent);
        $view = ($this->webUi['dashboard']['view'] ?? 'tree') === 'table' ? 'table' : 'tree';

        $response = $this->render($this->pages->template('dashboard', '@NowoUptimeMonitorBundle/dashboard/index.html.twig'), $this->pages->context([
            'page'                     => $this->buildPage($tenant, $tenantSlug, $pageEvent),
            'page_sections'            => ['before' => $pageEvent->getSections('before'), 'after' => $pageEvent->getSections('after')],
            'dashboard_view'           => $view,
            'dashboard_header'         => ($this->webUi['dashboard']['header'] ?? 'tenant') === 'monitors' ? 'monitors' : 'tenant',
            'tenant'                   => $tenant,
            'tenant_slug'              => $tenantSlug,
            'uptime_theme'             => $tenantSettings->getTheme(),
            'uptime_search_index'      => $tenantSettings->isSearchEngineIndexAllowed(),
            'heartbeat_bar_theme'      => $tenantSettings->getHeartbeatBarTheme(),
            'elapsed_time_display'     => $tenantSettings->getElapsedTimeDisplay(),
            'groups'                   => $groups,
            'stats'                    => $stats,
            'events'                   => $events,
            'events_filter_monitor'    => $eventsFilterMonitor,
            'events_filter_monitor_id' => $eventsFilterMonitor?->getId(),
            'dashboard_sync'           => $sync,
            'poll_interval_ms'         => $this->dashboardConfig['poll_interval_ms'] ?? 30000,
            'api_summary_url'          => $this->generateUrl('nowo_uptime_api_summary', ['tenantSlug' => $tenantSlug]),
            'layout_fragment_url'      => $this->generateUrl('nowo_uptime_dashboard_layout_fragment', ['tenantSlug' => $tenantSlug]),
            'overview_chart_json'      => json_encode($overview, JSON_THROW_ON_ERROR),
            'mercure_hub_url'          => $mercureHubUrl,
            'mercure_topic'            => $mercureTopic,
            'monitor_search_form'      => $this->createForm(MonitorSearchFormType::class),
        ] + ($view === 'table' ? $this->tableView($tenantSlug, $groups, $events, $request) : [])));

        if ($sync === 'mercure' && $mercureHubUrl !== null && $this->mercureAuthorization instanceof Authorization) {
            // @igor-ignore - HTTP handler delegates to services; no controller worker state.
            $this->mercureAuthorization->setCookie($request, [$mercureTopic]);
        }

        // @igor-ignore - HTTP handler delegates to services; no controller worker state.
        $response->headers->set('Cache-Control', 'no-store, no-cache, must-revalidate');

        return $response;
    }

    /**
     * Renders the dashboard layout fragment the polling client swaps in after each refresh.
     *
     * @param string $tenantSlug Tenant slug from the route
     * @param Request $request Current request (`monitor` query filters the event log)
     *
     * @throws NotFoundHttpException When the tenant does not exist
     */
    #[Route(
        path: '/{tenantSlug}/fragment/layout',
        name: 'nowo_uptime_dashboard_layout_fragment',
        requirements: ['tenantSlug' => '(?!tenants$)[a-z0-9\-]+'],
        priority: -1,
    )]
    public function layoutFragment(string $tenantSlug, Request $request): Response
    {
        $tenant = $this->tenantRepository->findOneBySlug($tenantSlug);
        if (!$tenant instanceof Tenant) {
            throw $this->createNotFoundException(sprintf('Tenant "%s" not found.', $tenantSlug));
        }

        $tenantSettings      = TenantSettings::from($tenant);
        $eventsFilterMonitor = $this->resolveEventsFilterMonitor($tenantSlug, $request);

        $response = $this->render('@NowoUptimeMonitorBundle/dashboard/_layout_fragment.html.twig', [
            'nowo_ui_bundle'       => UptimePages::BUNDLE,
            'tenant_slug'          => $tenantSlug,
            'heartbeat_bar_theme'  => $tenantSettings->getHeartbeatBarTheme(),
            'elapsed_time_display' => $tenantSettings->getElapsedTimeDisplay(),
            'groups'               => $this->dashboardViewBuilder->buildProjectGroups($tenantSlug),
            'stats'                => $this->dashboardViewBuilder->buildQuickStats($tenantSlug),
            'events'               => $this->dashboardViewBuilder->buildRecentEvents(
                $tenantSlug,
                $eventsFilterMonitor?->getId(),
            ),
            'events_filter_monitor_id' => $eventsFilterMonitor?->getId(),
        ]);

        $response->headers->set('Cache-Control', 'no-store, no-cache, must-revalidate');

        return $response;
    }

    /**
     * Variables of the table dashboard (`web_ui.dashboard.view: table`): the monitors as rows (a group, then its
     * monitors) with their row actions, and the latest checks newest first, one `web_ui.per_page` page (`?page=`).
     *
     * @param list<array{children: list<array<string, mixed>>, group_row: array<string, mixed>|null}> $groups
     * @param list<array<string, mixed>> $events
     *
     * @return array<string, mixed>
     */
    private function tableView(string $tenantSlug, array $groups, array $events, Request $request): array
    {
        $rows    = [];
        $actions = [];
        $targets = [];
        foreach ($groups as $section) {
            foreach ([...($section['group_row'] !== null ? [$section['group_row']] : []), ...$section['children']] as $row) {
                $rows[] = $row;
                /** @var Monitor $monitor */
                $monitor                          = $row['monitor'];
                $actions[(int) $monitor->getId()] = $this->actions?->monitor($tenantSlug, $monitor, true) ?? [];
                $targets[(int) $monitor->getId()] = SecretRedactor::maskUrl($monitor->getTarget());
            }
        }

        usort($events, static fn (array $a, array $b): int => strcmp((string) $b['checked_at'], (string) $a['checked_at']));
        $perPage = is_int($this->webUi['per_page'] ?? null) ? $this->webUi['per_page'] : 20;
        $pager   = Pagination::fromTotal(count($events), $request->query->getInt('page', 1), $perPage, [$perPage], variant: Pagination::VARIANT_COMPACT);

        return [
            'monitor_rows'        => $rows,
            'monitor_row_actions' => $actions,
            'monitor_targets'     => $targets,
            'dashboard_empty'     => new EmptyState(UptimePages::label('dashboard.empty.title'), UptimePages::label('dashboard.no_monitors'), $this->actions?->newMonitor($tenantSlug)),
            'events_page'         => array_slice($events, ($pager->page - 1) * $perPage, $perPage),
            'events_pagination'   => $pager,
        ];
    }

    /**
     * Dashboard view model: tenant name (slug as intro when it differs) or "Monitors" (`web_ui.dashboard.header`), and
     * the header actions of
     * {@see UptimeActions} (`web_ui.dashboard.actions`, each one for the users allowed to use it) plus the host's
     * ({@see UptimePageEvent}); New monitor opens the monitor form dialog (uptime-dashboard.js) and is a plain link
     * without JavaScript.
     */
    private function buildPage(Tenant $tenant, string $tenantSlug, UptimePageEvent $event): DashboardPage
    {
        $actions = $this->actions instanceof UptimeActions ? $this->actions->dashboardHeader($tenantSlug) : $this->defaultActions($tenantSlug);

        $monitorsHeader = ($this->webUi['dashboard']['header'] ?? 'tenant') === 'monitors';

        return new DashboardPage(
            new PageHeader(
                $monitorsHeader ? UptimePages::label('dashboard.table.title') : $tenant->getName(),
                !$monitorsHeader && strtolower($tenantSlug) !== strtolower($tenant->getName()) ? $tenantSlug : null,
                [...$actions, ...$event->getActions()],
            ),
            bundle: UptimePages::BUNDLE,
            layout: $this->pages->layout(),
            attributes: ['data-testid' => 'uptime-dashboard'],
        );
    }

    /**
     * Header actions of a controller built without {@see UptimeActions}: Settings, Status page, New monitor.
     *
     * @return list<Action>
     */
    private function defaultActions(string $tenantSlug): array
    {
        $newUrl = $this->generateUrl('nowo_uptime_monitor_new', ['tenantSlug' => $tenantSlug]);

        return [
            new Action('custom', UptimePages::label('dashboard.settings'), $this->generateUrl('nowo_uptime_settings_index', ['tenantSlug' => $tenantSlug]), icon: 'config', attributes: ['data-testid' => 'uptime-dashboard-settings']),
            new Action('view', UptimePages::label('dashboard.status_page'), $this->generateUrl('nowo_uptime_status_page', ['tenantSlug' => $tenantSlug]), attributes: ['data-testid' => 'uptime-dashboard-status-page']),
            new Action('create', UptimePages::label('monitor.new'), $newUrl, variant: 'primary', attributes: [
                'data-monitor-form-open'   => true,
                'data-monitor-form-url'    => $newUrl,
                'data-monitor-form-return' => 'dashboard',
                'data-testid'              => 'uptime-monitor-new',
            ]),
        ];
    }

    private function resolveEventsFilterMonitor(string $tenantSlug, Request $request): ?Monitor
    {
        $monitorId = $request->query->getInt('monitor');
        if ($monitorId <= 0) {
            return null;
        }

        $monitor = $this->monitorRepository->find($monitorId);
        if ($monitor === null || $monitor->getTenant()->getSlug() !== $tenantSlug) {
            return null;
        }

        return $monitor;
    }
}
