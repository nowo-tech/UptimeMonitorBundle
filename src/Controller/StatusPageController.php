<?php

declare(strict_types=1);

namespace Nowo\UptimeMonitorBundle\Controller;

use Nowo\UptimeMonitorBundle\Enum\CheckStatus;
use Nowo\UptimeMonitorBundle\Repository\CheckResultRepository;
use Nowo\UptimeMonitorBundle\Repository\MonitorRepository;
use Nowo\UptimeMonitorBundle\Repository\TenantRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

use function is_string;
use function sprintf;

/**
 * Public read-only status page per tenant (no CRUD links).
 */
final class StatusPageController extends AbstractController
{
    /**
     * @param array<string, mixed> $statusPageConfig
     */
    public function __construct(
        private readonly TenantRepository $tenantRepository,
        private readonly MonitorRepository $monitorRepository,
        private readonly CheckResultRepository $checkResultRepository,
        #[Autowire('%nowo_uptime_monitor.status_page%')]
        private readonly array $statusPageConfig,
    ) {
    }

    #[Route(
        path: '/{tenantSlug}',
        name: 'nowo_uptime_status_page',
        requirements: ['tenantSlug' => '[a-z0-9\-]+'],
    )]
    public function index(string $tenantSlug): Response
    {
        if (!($this->statusPageConfig['enabled'] ?? true)) {
            throw $this->createNotFoundException('Status page is disabled.');
        }

        $tenant = $this->tenantRepository->findOneBySlug($tenantSlug);
        if ($tenant === null) {
            throw $this->createNotFoundException(sprintf('Tenant "%s" not found.', $tenantSlug));
        }

        $monitors = array_values(array_filter(
            $this->monitorRepository->findByTenantSlug($tenantSlug),
            static fn ($m) => !$m->isPaused(),
        ));

        $ids = array_values(array_filter(array_map(
            static fn ($m) => $m->getId(),
            $monitors,
        )));
        $latestResults = $this->checkResultRepository->findLatestByMonitorIds($ids);

        $rows = [];
        foreach ($monitors as $monitor) {
            $monitorId = $monitor->getId();
            $latest    = $monitorId !== null ? ($latestResults[$monitorId] ?? null) : null;
            $rows[]    = [
                'monitor' => $monitor,
                'latest'  => $latest,
            ];
        }

        return $this->render('@NowoUptimeMonitorBundle/status/index.html.twig', [
            'tenant'         => $tenant,
            'tenant_slug'    => $tenantSlug,
            'rows'           => $rows,
            'overall_status' => $this->resolveOverallStatus($rows),
            'show_latency'   => (bool) ($this->statusPageConfig['show_latency'] ?? true),
            'title'          => is_string($this->statusPageConfig['title'] ?? null)
                ? $this->statusPageConfig['title']
                : $tenant->getName(),
        ]);
    }

    /**
     * @param list<array{monitor: \Nowo\UptimeMonitorBundle\Entity\Monitor, latest: ?\Nowo\UptimeMonitorBundle\Entity\CheckResult}> $rows
     */
    private function resolveOverallStatus(array $rows): string
    {
        if ($rows === []) {
            return 'unknown';
        }

        $hasDown     = false;
        $hasDegraded = false;
        $hasUnknown  = false;

        foreach ($rows as $row) {
            $status = $row['latest']?->getStatus() ?? CheckStatus::Unknown;
            if ($status === CheckStatus::Down) {
                $hasDown = true;
            } elseif ($status === CheckStatus::Degraded) {
                $hasDegraded = true;
            } elseif ($status === CheckStatus::Unknown) {
                $hasUnknown = true;
            }
        }

        if ($hasDown) {
            return 'major';
        }

        if ($hasDegraded) {
            return 'degraded';
        }

        if ($hasUnknown) {
            return 'unknown';
        }

        return 'operational';
    }
}
