<?php

declare(strict_types=1);

namespace Nowo\UptimeMonitorBundle\Controller;

use Nowo\UptimeMonitorBundle\Entity\Monitor;
use Nowo\UptimeMonitorBundle\Monitor\TenantSettings;
use Nowo\UptimeMonitorBundle\Repository\MonitorRepository;
use Nowo\UptimeMonitorBundle\Repository\TenantRepository;
use Nowo\UptimeMonitorBundle\Service\AggregateChartService;
use Nowo\UptimeMonitorBundle\Service\DashboardSyncDispatcher;
use Nowo\UptimeMonitorBundle\Service\DashboardViewBuilder;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Mercure\Authorization;
use Symfony\Component\Mercure\HubInterface;
use Symfony\Component\Routing\Attribute\Route;

use function sprintf;

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
    ) {
    }

    #[Route(
        path: '/{tenantSlug}',
        name: 'nowo_uptime_dashboard',
        requirements: ['tenantSlug' => '(?!tenants$)[a-z0-9\-]+'],
        priority: -1,
    )]
    public function index(string $tenantSlug, Request $request): Response
    {
        $tenant = $this->tenantRepository->findOneBySlug($tenantSlug);
        if ($tenant === null) {
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
            if ($this->mercureHub !== null) {
                $mercureHubUrl = $this->mercureHub->getPublicUrl();
            }
        }

        $tenantSettings = TenantSettings::from($tenant);

        $response = $this->render('@NowoUptimeMonitorBundle/dashboard/index.html.twig', [
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
        ]);

        if ($sync === 'mercure' && $mercureHubUrl !== null && $mercureTopic !== null && $this->mercureAuthorization !== null) {
            $this->mercureAuthorization->setCookie($request, [$mercureTopic]);
        }

        $response->headers->set('Cache-Control', 'no-store, no-cache, must-revalidate');

        return $response;
    }

    #[Route(
        path: '/{tenantSlug}/fragment/layout',
        name: 'nowo_uptime_dashboard_layout_fragment',
        requirements: ['tenantSlug' => '(?!tenants$)[a-z0-9\-]+'],
        priority: -1,
    )]
    public function layoutFragment(string $tenantSlug, Request $request): Response
    {
        $tenant = $this->tenantRepository->findOneBySlug($tenantSlug);
        if ($tenant === null) {
            throw $this->createNotFoundException(sprintf('Tenant "%s" not found.', $tenantSlug));
        }

        $tenantSettings      = TenantSettings::from($tenant);
        $eventsFilterMonitor = $this->resolveEventsFilterMonitor($tenantSlug, $request);

        $response = $this->render('@NowoUptimeMonitorBundle/dashboard/_layout_fragment.html.twig', [
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
