<?php

declare(strict_types=1);

namespace Nowo\UptimeMonitorBundle\Service;

use Nowo\UptimeMonitorBundle\Entity\CheckResult;
use Nowo\UptimeMonitorBundle\Entity\Monitor;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Mercure\HubInterface;
use Symfony\Component\Mercure\Update;

use const JSON_THROW_ON_ERROR;

/**
 * Pushes dashboard updates to Mercure when {@see Configuration} dashboard.sync is mercure.
 */
final class DashboardSyncDispatcher
{
    /**
     * @param array<string, mixed> $dashboardConfig
     */
    public function __construct(
        private readonly SummaryPayloadBuilder $payloadBuilder,
        #[Autowire('%nowo_uptime_monitor.dashboard%')]
        private readonly array $dashboardConfig,
        #[Autowire('@?mercure.hub.default')]
        private readonly ?HubInterface $hub = null,
    ) {
    }

    public function dispatchTenantRefresh(string $tenantSlug): void
    {
        if (($this->dashboardConfig['sync'] ?? 'polling') !== 'mercure') {
            return;
        }

        if ($this->hub === null) {
            return;
        }

        $topic   = $this->resolveTopic($tenantSlug);
        $payload = json_encode(
            array_merge(
                ['type' => 'dashboard_reset'],
                $this->payloadBuilder->buildTenantSummary($tenantSlug),
            ),
            JSON_THROW_ON_ERROR,
        );

        $this->hub->publish(new Update(
            $topic,
            $payload,
            (bool) ($this->dashboardConfig['mercure']['private'] ?? true),
        ));
    }

    public function dispatchAfterCheck(Monitor $monitor, CheckResult $result): void
    {
        if (($this->dashboardConfig['sync'] ?? 'polling') !== 'mercure') {
            return;
        }

        if ($this->hub === null) {
            return;
        }

        $tenant = $monitor->getTenant();
        $slug   = $tenant->getSlug();
        $topic  = $this->resolveTopic($slug);

        $payload = json_encode(
            $this->payloadBuilder->buildMercureUpdatePayload($monitor, $result),
            JSON_THROW_ON_ERROR,
        );

        $this->hub->publish(new Update(
            $topic,
            $payload,
            (bool) ($this->dashboardConfig['mercure']['private'] ?? true),
        ));
    }

    public function resolveTopic(string $tenantSlug): string
    {
        $template = (string) ($this->dashboardConfig['mercure']['topic_template'] ?? '/uptime/{tenant}');

        return str_replace('{tenant}', $tenantSlug, $template);
    }

    public function isMercureEnabled(): bool
    {
        return ($this->dashboardConfig['sync'] ?? 'polling') === 'mercure' && $this->hub !== null;
    }
}
