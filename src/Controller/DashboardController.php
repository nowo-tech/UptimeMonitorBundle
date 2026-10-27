<?php

declare(strict_types=1);

namespace Nowo\UptimeMonitorBundle\Controller;

use Nowo\UiKitBundle\View\Action;
use Nowo\UiKitBundle\View\DashboardPage;
use Nowo\UiKitBundle\View\PageHeader;
use Nowo\UptimeMonitorBundle\Entity\Monitor;
use Nowo\UptimeMonitorBundle\Entity\Tenant;
use Nowo\UptimeMonitorBundle\Form\MonitorSearchFormType;
use Nowo\UptimeMonitorBundle\Monitor\TenantSettings;
use Nowo\UptimeMonitorBundle\Repository\MonitorRepository;
use Nowo\UptimeMonitorBundle\Repository\TenantRepository;
use Nowo\UptimeMonitorBundle\Service\AggregateChartService;
use Nowo\UptimeMonitorBundle\Service\DashboardSyncDispatcher;
use Nowo\UptimeMonitorBundle\Service\DashboardViewBuilder;
use Nowo\UptimeMonitorBundle\Ui\UptimePages;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Mercure\Authorization;
use Symfony\Component\Mercure\HubInterface;
use Symfony\Component\Routing\Attribute\Route;

use function sprintf;
use function strtolower;

use const JSON_THROW_ON_ERROR;

/**
 * Twig shell for the uptime dashboard (server-rendered list + polling).
 */
final class DashboardController extends AbstractController
{
    /**
     * @param array<string, mixed> $dashboardConfig
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

        $response = $this->render($this->pages->template('dashboard', '@NowoUptimeMonitorBundle/dashboard/index.html.twig'), $this->pages->context([
            'page'                     => $this->buildPage($tenant, $tenantSlug),
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
        ]));

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
     * Dashboard view model: tenant name (slug as intro when it differs) and the Settings / Status page / New monitor
     * actions; the last one opens the monitor form dialog (uptime-dashboard.js) and is a plain link without JavaScript.
     */
    private function buildPage(Tenant $tenant, string $tenantSlug): DashboardPage
    {
        $newUrl = $this->generateUrl('nowo_uptime_monitor_new', ['tenantSlug' => $tenantSlug]);

        return new DashboardPage(
            new PageHeader(
                $tenant->getName(),
                strtolower($tenantSlug) !== strtolower($tenant->getName()) ? $tenantSlug : null,
                [
                    new Action('custom', UptimePages::label('dashboard.settings'), $this->generateUrl('nowo_uptime_settings_index', ['tenantSlug' => $tenantSlug]), icon: 'config', attributes: ['data-testid' => 'uptime-dashboard-settings']),
                    new Action('view', UptimePages::label('dashboard.status_page'), $this->generateUrl('nowo_uptime_status_page', ['tenantSlug' => $tenantSlug]), attributes: ['data-testid' => 'uptime-dashboard-status-page']),
                    new Action('create', UptimePages::label('monitor.new'), $newUrl, variant: 'primary', attributes: [
                        'data-monitor-form-open'   => true,
                        'data-monitor-form-url'    => $newUrl,
                        'data-monitor-form-return' => 'dashboard',
                        'data-testid'              => 'uptime-monitor-new',
                    ]),
                ],
            ),
            bundle: UptimePages::BUNDLE,
            layout: $this->pages->layout(),
            attributes: ['data-testid' => 'uptime-dashboard'],
        );
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
