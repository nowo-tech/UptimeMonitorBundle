<?php

declare(strict_types=1);

namespace Nowo\UptimeMonitorBundle\Service;

use DateTimeImmutable;
use DateTimeInterface;
use Doctrine\ORM\EntityManagerInterface;
use InvalidArgumentException;
use Nowo\UptimeMonitorBundle\Entity\Monitor;
use Nowo\UptimeMonitorBundle\Entity\Tenant;
use Nowo\UptimeMonitorBundle\Enum\MonitorType;
use Nowo\UptimeMonitorBundle\Form\Model\MonitorFormData;
use Nowo\UptimeMonitorBundle\Repository\MonitorRepository;

use function is_array;
use function is_string;

/**
 * Export/import monitors (Uptime Kuma backup JSON subset — no history).
 */
final class MonitorBackupService
{
    public const IMPORT_KEEP      = 'keep';
    public const IMPORT_SKIP      = 'skip';
    public const IMPORT_OVERWRITE = 'overwrite';

    public function __construct(
        private readonly MonitorRepository $monitorRepository,
        private readonly MonitorFactory $monitorFactory,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    /**
     * @return array{version: int, exported_at: string, tenant: string, monitors: list<array<string, mixed>>}
     */
    public function export(Tenant $tenant): array
    {
        $monitors = [];
        foreach ($this->monitorRepository->findByTenantSlug($tenant->getSlug()) as $monitor) {
            if ($monitor->isGroup()) {
                continue;
            }

            $monitors[] = $this->serializeMonitor($monitor);
        }

        return [
            'version'     => 1,
            'exported_at' => (new DateTimeImmutable())->format(DateTimeInterface::ATOM),
            'tenant'      => $tenant->getSlug(),
            'monitors'    => $monitors,
        ];
    }

    /**
     * @param array<string, mixed> $payload
     *
     * @return array{imported: int, skipped: int, overwritten: int}
     */
    public function import(Tenant $tenant, array $payload, string $mode = self::IMPORT_SKIP): array
    {
        $rows = $payload['monitors'] ?? [];
        if (!is_array($rows)) {
            throw new InvalidArgumentException('Invalid backup: missing monitors array.');
        }

        $imported    = 0;
        $skipped     = 0;
        $overwritten = 0;

        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }

            $name = isset($row['name']) && is_string($row['name']) ? $row['name'] : null;
            if ($name === null || $name === '') {
                continue;
            }

            $existing = $this->findByName($tenant, $name);
            if ($existing !== null) {
                if ($mode === self::IMPORT_SKIP) {
                    ++$skipped;
                    continue;
                }

                if ($mode === self::IMPORT_OVERWRITE) {
                    $this->applyRow($existing, $row);
                    ++$overwritten;
                    continue;
                }

                ++$skipped;
                continue;
            }

            $data    = $this->rowToFormData($row);
            $monitor = $this->monitorFactory->createFromFormData($tenant, $data);
            $this->entityManager->persist($monitor);
            ++$imported;
        }

        $this->entityManager->flush();

        return ['imported' => $imported, 'skipped' => $skipped, 'overwritten' => $overwritten];
    }

    /**
     * @return array<string, mixed>
     */
    private function serializeMonitor(Monitor $monitor): array
    {
        $data = $this->monitorFactory->toFormData($monitor);

        return [
            'name'                   => $monitor->getName(),
            'type'                   => $monitor->getType()->value,
            'target'                 => $monitor->getTarget(),
            'config'                 => $monitor->getConfig(),
            'interval_seconds'       => $monitor->getIntervalSeconds(),
            'retries'                => $monitor->getRetries(),
            'retry_interval_seconds' => $monitor->getRetryIntervalSeconds(),
            'paused'                 => $monitor->isPaused(),
            'project'                => $monitor->getProject(),
            'form'                   => [
                'url'                   => $data->url,
                'method'                => $data->method,
                'expected_status_codes' => $data->expectedStatusCodes,
                'host'                  => $data->host,
                'port'                  => $data->port,
            ],
        ];
    }

    private function findByName(Tenant $tenant, string $name): ?Monitor
    {
        foreach ($this->monitorRepository->findByTenantSlug($tenant->getSlug()) as $monitor) {
            if ($monitor->getName() === $name) {
                return $monitor;
            }
        }

        return null;
    }

    /**
     * @param array<string, mixed> $row
     */
    private function applyRow(Monitor $monitor, array $row): void
    {
        $data = $this->rowToFormData($row);
        $this->monitorFactory->applyFormData($monitor, $data);
    }

    /**
     * @param array<string, mixed> $row
     */
    private function rowToFormData(array $row): MonitorFormData
    {
        $data                       = new MonitorFormData();
        $data->name                 = (string) ($row['name'] ?? '');
        $type                       = (string) ($row['type'] ?? MonitorType::Https->value);
        $data->type                 = MonitorType::tryFrom($type) ?? MonitorType::Https;
        $data->intervalSeconds      = (int) ($row['interval_seconds'] ?? 60);
        $data->retries              = (int) ($row['retries'] ?? 0);
        $data->retryIntervalSeconds = (int) ($row['retry_interval_seconds'] ?? 60);
        $data->paused               = (bool) ($row['paused'] ?? false);
        $data->project              = (string) ($row['project'] ?? '');

        $config = $row['config'] ?? [];
        if (is_array($config)) {
            $data->url    = isset($config['url']) && is_string($config['url']) ? $config['url'] : (string) ($row['target'] ?? '');
            $data->method = isset($config['method']) && is_string($config['method']) ? $config['method'] : 'GET';
            if (isset($config['expected_status_codes']) && is_array($config['expected_status_codes'])) {
                $data->expectedStatusCodes = implode(',', array_map('strval', $config['expected_status_codes']));
            }
        }

        $form = $row['form'] ?? [];
        if (is_array($form)) {
            if (isset($form['url']) && is_string($form['url'])) {
                $data->url = $form['url'];
            }
            if (isset($form['expected_status_codes']) && is_string($form['expected_status_codes'])) {
                $data->expectedStatusCodes = $form['expected_status_codes'];
            }
        }

        return $data;
    }
}
