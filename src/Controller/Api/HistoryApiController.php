<?php

declare(strict_types=1);

namespace Nowo\UptimeMonitorBundle\Controller\Api;

use DateTimeInterface;
use Nowo\UptimeMonitorBundle\Repository\CheckResultRepository;
use Nowo\UptimeMonitorBundle\Repository\MonitorRepository;
use Nowo\UptimeMonitorBundle\Repository\TenantRepository;
use Nowo\UptimeMonitorBundle\Service\DashboardViewBuilder;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\Routing\Attribute\Route;

/**
 * JSON API for per-monitor check history (detail view and polling).
 */
#[AsController]
final class HistoryApiController extends AbstractController
{
    public function __construct(
        private readonly TenantRepository $tenantRepository,
        private readonly MonitorRepository $monitorRepository,
        private readonly CheckResultRepository $checkResultRepository,
        private readonly DashboardViewBuilder $dashboardViewBuilder,
    ) {
    }

    #[Route(
        path: '/api/uptime/{tenantSlug}/monitors/{id}/history',
        name: 'nowo_uptime_api_monitor_history',
        requirements: ['tenantSlug' => '[a-z0-9\-]+', 'id' => '\d+'],
        methods: ['GET'],
    )]
    public function monitorHistory(string $tenantSlug, int $id): JsonResponse
    {
        $tenant = $this->tenantRepository->findOneBySlug($tenantSlug);
        if ($tenant === null) {
            return $this->json(['error' => 'Tenant not found'], Response::HTTP_NOT_FOUND);
        }

        $monitor = $this->monitorRepository->find($id);
        if ($monitor === null || $monitor->getTenant()->getSlug() !== $tenantSlug) {
            return $this->json(['error' => 'Monitor not found'], Response::HTTP_NOT_FOUND);
        }

        $detail = $this->dashboardViewBuilder->buildMonitorDetail($monitor);
        $latest = $this->checkResultRepository->findLatestForMonitor($monitor);

        return $this->json([
            'monitor_id'      => $id,
            'last_status'     => $latest?->getStatus()->value,
            'last_checked_at' => $latest?->getCheckedAt()->format(DateTimeInterface::ATOM),
            'uptime_24h'      => $detail['uptime_24h'],
            'uptime_30d'      => $detail['uptime_30d'],
            'history'         => $detail['history'],
            'events'          => $detail['events'],
            'latency_series'  => $detail['latency_series'],
        ]);
    }
}
