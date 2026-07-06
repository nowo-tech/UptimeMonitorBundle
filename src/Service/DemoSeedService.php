<?php

declare(strict_types=1);

namespace Nowo\UptimeMonitorBundle\Service;

use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Nowo\UptimeMonitorBundle\Entity\Monitor;
use Nowo\UptimeMonitorBundle\Entity\Tenant;
use Nowo\UptimeMonitorBundle\Enum\MonitorType;
use Nowo\UptimeMonitorBundle\Repository\MonitorRepository;
use Nowo\UptimeMonitorBundle\Repository\TenantRepository;

use function count;

/**
 * Seeds demo tenants with one project group and local HTTP probe monitors.
 */
final class DemoSeedService
{
    /** Base URL for demo probe routes when checks run inside the demo Docker network. */
    private const DEMO_PROBE_BASE_URL = 'http://php';

    private const DEMO_GROUP_KEY = 'demo';

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly TenantRepository $tenantRepository,
        private readonly MonitorRepository $monitorRepository,
    ) {
    }

    /**
     * Removes all monitors for the tenant and seeds a minimal demo tree.
     *
     * @return array{tenant: Tenant, monitors_created: int, monitors_removed: int}
     */
    public function freshSeed(string $tenantSlug = 'main', string $tenantName = 'Main'): array
    {
        $removed = $this->removeAllMonitors($tenantSlug);

        $result                     = $this->seed($tenantSlug, $tenantName);
        $result['monitors_removed'] = $removed;

        return $result;
    }

    /**
     * @return array{tenant: Tenant, monitors_created: int}
     */
    public function seed(string $tenantSlug = 'main', string $tenantName = 'Main'): array
    {
        $tenant = $this->tenantRepository->findOneBySlug($tenantSlug);
        if ($tenant === null) {
            $tenant = new Tenant($tenantSlug, $tenantName);
            $this->entityManager->persist($tenant);
            $this->entityManager->flush();
        }

        $groupLabels = [
            self::DEMO_GROUP_KEY => '[demo]',
        ];

        /** @var array<string, Monitor> $groups */
        $groups  = [];
        $created = 0;

        foreach ($groupLabels as $key => $label) {
            $existing = $this->monitorRepository->findOneBy([
                'tenant' => $tenant,
                'name'   => $label,
                'type'   => MonitorType::Group,
            ]);

            if ($existing === null) {
                $existing = new Monitor($tenant, $label, MonitorType::Group, $label);
                $existing->setIntervalSeconds(30)->setNextCheckAt(new DateTimeImmutable());
                $this->entityManager->persist($existing);
                ++$created;
            } else {
                $existing->setIntervalSeconds(30)->setNextCheckAt(new DateTimeImmutable());
            }

            $groups[$key] = $existing;
        }

        foreach ($this->monitorDefinitions() as $definition) {
            $existing = $this->monitorRepository->findOneBy([
                'tenant' => $tenant,
                'name'   => $definition['name'],
            ]);

            $group = isset($definition['group'], $groups[$definition['group']])
                ? $groups[$definition['group']]
                : null;

            if ($existing !== null) {
                $this->applyDefinition($existing, $definition, $group);
                continue;
            }

            $monitor = new Monitor(
                $tenant,
                $definition['name'],
                $definition['type'],
                $definition['target'],
            );
            $this->applyDefinition($monitor, $definition, $group);
            $monitor->setNextCheckAt(new DateTimeImmutable());

            $this->entityManager->persist($monitor);
            ++$created;
        }

        $this->entityManager->flush();

        return ['tenant' => $tenant, 'monitors_created' => $created];
    }

    private function removeAllMonitors(string $tenantSlug): int
    {
        $monitors = $this->monitorRepository->findByTenantSlug($tenantSlug);
        if ($monitors === []) {
            return 0;
        }

        usort(
            $monitors,
            static fn (Monitor $a, Monitor $b): int => ($a->getParent() === null ? 1 : 0) <=> ($b->getParent() === null ? 1 : 0),
        );

        foreach ($monitors as $monitor) {
            $this->entityManager->remove($monitor);
        }

        $this->entityManager->flush();

        return count($monitors);
    }

    /**
     * Local HTTP probes ({@see UptimeDemoProbeController} in the demo app).
     *
     * @return list<array<string, mixed>>
     */
    private function monitorDefinitions(): array
    {
        return [
            [
                'name'   => 'demo_uptime_ok',
                'group'  => self::DEMO_GROUP_KEY,
                'type'   => MonitorType::Http,
                'target' => self::DEMO_PROBE_BASE_URL . '/demo/uptime/ok',
                'config' => [
                    'url'                   => self::DEMO_PROBE_BASE_URL . '/demo/uptime/ok',
                    'expected_status_codes' => [200],
                ],
                'interval' => 20,
            ],
            [
                'name'   => 'demo_uptime_flaky',
                'group'  => self::DEMO_GROUP_KEY,
                'type'   => MonitorType::Http,
                'target' => self::DEMO_PROBE_BASE_URL . '/demo/uptime/flaky/3',
                'config' => [
                    'url'                   => self::DEMO_PROBE_BASE_URL . '/demo/uptime/flaky/3',
                    'expected_status_codes' => [200],
                ],
                'interval' => 20,
            ],
        ];
    }

    /**
     * @param array<string, mixed> $definition
     */
    private function applyDefinition(Monitor $monitor, array $definition, ?Monitor $group): void
    {
        $monitor
            ->setType($definition['type'])
            ->setTarget($definition['target'])
            ->setConfig($definition['config'])
            ->setIntervalSeconds($definition['interval'])
            ->setLastKnownStatus(null)
            ->setLastAlertAt(null);

        if ($group !== null) {
            $monitor->setParent($group)->setProject(null);
        }
    }
}
