<?php

declare(strict_types=1);

namespace Nowo\UptimeMonitorBundle\Controller\Api;

use Nowo\UptimeMonitorBundle\Entity\Monitor;
use Nowo\UptimeMonitorBundle\Entity\Tenant;
use Nowo\UptimeMonitorBundle\Enum\AggregatePeriod;
use Nowo\UptimeMonitorBundle\Repository\MonitorRepository;
use Nowo\UptimeMonitorBundle\Repository\TenantRepository;
use Nowo\UptimeMonitorBundle\Service\AggregateChartService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * JSON API for rollup charts (per monitor and tenant overview).
 */
final class AggregatesApiController extends AbstractController
{
    /**
     * Creates the chart API controller.
     *
     * @param AggregateChartService $chartService Builds the chart series from stored aggregates
     */
    public function __construct(
        private readonly TenantRepository $tenantRepository,
        private readonly MonitorRepository $monitorRepository,
        private readonly AggregateChartService $chartService,
    ) {
    }

    /**
     * Returns a monitor chart series; `period` (hour, day, month, year) and `days` (1-365) select the range.
     *
     * @param string $tenantSlug Tenant slug from the route
     * @param int $id Monitor id
     * @param Request $request Current request (`period`, `days` query)
     */
    #[Route(
        path: '/api/uptime/{tenantSlug}/monitors/{id}/aggregates',
        name: 'nowo_uptime_api_monitor_aggregates',
        requirements: ['id' => '\d+'],
        methods: ['GET'],
    )]
    public function monitorAggregates(string $tenantSlug, int $id, Request $request): JsonResponse
    {
        $monitor = $this->resolveMonitor($tenantSlug, $id);
        if ($monitor instanceof JsonResponse) {
            return $monitor;
        }

        $period = AggregatePeriod::tryFrom((string) $request->query->get('period', 'day'))
            ?? AggregatePeriod::Day;
        $days = max(1, min(365, $request->query->getInt('days', 30)));

        return $this->json([
            'monitor_id' => $monitor->getId(),
            'period'     => $period->value,
            'days'       => $days,
            'series'     => $this->chartService->buildMonitorSeries($monitor, $period, $days),
        ]);
    }

    /**
     * Returns the daily uptime series of every monitor of the tenant; `days` (1-90) selects the range.
     *
     * @param string $tenantSlug Tenant slug from the route
     * @param Request $request Current request (`days` query)
     */
    #[Route(
        path: '/api/uptime/{tenantSlug}/aggregates/overview',
        name: 'nowo_uptime_api_tenant_overview',
        methods: ['GET'],
    )]
    public function tenantOverview(string $tenantSlug, Request $request): JsonResponse
    {
        if (!$this->tenantRepository->findOneBySlug($tenantSlug) instanceof Tenant) {
            return $this->json(['error' => 'Tenant not found'], Response::HTTP_NOT_FOUND);
        }

        $days = max(1, min(90, $request->query->getInt('days', 7)));

        return $this->json([
            'tenant'   => $tenantSlug,
            'days'     => $days,
            'monitors' => $this->chartService->buildTenantOverview($tenantSlug, $days),
        ]);
    }

    private function resolveMonitor(string $tenantSlug, int $id): Monitor|JsonResponse
    {
        $monitor = $this->monitorRepository->find($id);
        if ($monitor === null || $monitor->getTenant()->getSlug() !== $tenantSlug) {
            return $this->json(['error' => 'Monitor not found'], Response::HTTP_NOT_FOUND);
        }

        return $monitor;
    }
}
