<?php

declare(strict_types=1);

namespace Nowo\UptimeMonitorBundle\Service;

use DateTimeInterface;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use InvalidArgumentException;
use Nowo\UptimeMonitorBundle\Entity\Monitor;
use Nowo\UptimeMonitorBundle\Entity\Tenant;
use Nowo\UptimeMonitorBundle\Enum\MonitorType;
use Nowo\UptimeMonitorBundle\Form\Model\MonitorFormData;
use Nowo\UptimeMonitorBundle\Repository\MonitorRepository;
use Nowo\UptimeMonitorBundle\Security\SecretRedactor;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\Clock\NativeClock;
use Symfony\Component\Validator\Validator\ValidatorInterface;
use Throwable;

use function count;
use function is_array;
use function is_string;
use function sprintf;
use function strtoupper;

/**
 * Export/import monitors (Uptime Kuma backup JSON subset — no history).
 *
 * SEC-004 L7: exports redact secrets (URL / proxy userinfo, basic-auth password, custom header values); imports
 * accept at most {@see self::MAX_IMPORT_ROWS} rows, look names up once, and skip rows that fail the monitor form
 * constraints (counted as `invalid`). Export and import are logged with the tenant slug and counts (REQ-OBS-001).
 */
final readonly class MonitorBackupService
{
    public const IMPORT_KEEP      = 'keep';
    public const IMPORT_SKIP      = 'skip';
    public const IMPORT_OVERWRITE = 'overwrite';
    public const MAX_IMPORT_ROWS  = 1000;

    /**
     * Creates the backup service.
     *
     * @param ManagerRegistry|null $registry Resets closed managers after a failed import flush
     * @param ClockInterface $clock Clock for the export timestamp
     * @param ValidatorInterface|null $validator Validates import rows against the monitor form constraints
     * @param LoggerInterface $logger Receives export / import start, success (counts) and failure (exception class)
     */
    public function __construct(
        private MonitorRepository $monitorRepository,
        private MonitorFactory $monitorFactory,
        private EntityManagerInterface $entityManager,
        private ?ManagerRegistry $registry = null,
        private ClockInterface $clock = new NativeClock(),
        private ?ValidatorInterface $validator = null,
        private LoggerInterface $logger = new NullLogger(),
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

        $this->logger->info('Uptime backup exported for tenant {tenant}: {count} monitor(s)', [
            'bundle' => 'NowoUptimeMonitorBundle',
            'tenant' => $tenant->getSlug(),
            'count'  => count($monitors),
        ]);

        return [
            'version'     => 1,
            'exported_at' => $this->clock->now()->format(DateTimeInterface::ATOM),
            'tenant'      => $tenant->getSlug(),
            'monitors'    => $monitors,
        ];
    }

    /**
     * @param array<string, mixed> $payload
     *
     * @return array{imported: int, skipped: int, overwritten: int, invalid: int}
     */
    public function import(Tenant $tenant, array $payload, string $mode = self::IMPORT_SKIP): array
    {
        $context = ['bundle' => 'NowoUptimeMonitorBundle', 'tenant' => $tenant->getSlug(), 'mode' => $mode];
        $rows    = $payload['monitors'] ?? [];
        if (!is_array($rows)) {
            throw new InvalidArgumentException('Invalid backup: missing monitors array.');
        }
        if (count($rows) > self::MAX_IMPORT_ROWS) {
            throw new InvalidArgumentException(sprintf('Invalid backup: more than %d monitors.', self::MAX_IMPORT_ROWS));
        }

        $this->logger->info('Uptime backup import started for tenant {tenant}: {rows} row(s)', $context + ['rows' => count($rows)]);

        // One lookup for the whole import (name => monitor), not one query per row.
        $byName = [];
        foreach ($this->monitorRepository->findByTenantSlug($tenant->getSlug()) as $monitor) {
            $byName[$monitor->getName()] ??= $monitor;
        }

        $imported    = 0;
        $skipped     = 0;
        $overwritten = 0;
        $invalid     = 0;

        foreach ($rows as $row) {
            if (!is_array($row)) {
                ++$invalid;
                continue;
            }

            $name = isset($row['name']) && is_string($row['name']) ? $row['name'] : null;
            if ($name === null || $name === '') {
                ++$invalid;
                continue;
            }

            $data = $this->rowToFormData($row);
            if ($this->validator instanceof ValidatorInterface && $this->validator->validate($data)->count() > 0) {
                ++$invalid;
                continue;
            }

            $existing = $byName[$name] ?? null;
            if ($existing instanceof Monitor) {
                if ($mode === self::IMPORT_OVERWRITE) {
                    $this->monitorFactory->applyFormData($existing, $data);
                    ++$overwritten;
                    continue;
                }

                ++$skipped;
                continue;
            }

            $monitor = $this->monitorFactory->createFromFormData($tenant, $data);
            $this->entityManager->persist($monitor);
            $byName[$name] = $monitor;
            ++$imported;
        }

        try {
            $this->entityManager->flush();
        } catch (Throwable $e) {
            if ($this->registry instanceof ManagerRegistry && !$this->entityManager->isOpen()) {
                $this->resetClosedManagers($this->registry);
            }

            $this->logger->error('Uptime backup import failed for tenant {tenant}: {error}', $context + ['error' => $e::class]);

            throw $e;
        }

        $result = ['imported' => $imported, 'skipped' => $skipped, 'overwritten' => $overwritten, 'invalid' => $invalid];
        $this->logger->info('Uptime backup imported for tenant {tenant}', $context + $result);

        return $result;
    }

    private function resetClosedManagers(ManagerRegistry $registry): void
    {
        foreach ($registry->getManagers() as $name => $manager) {
            if ($manager instanceof EntityManagerInterface && !$manager->isOpen()) {
                $registry->resetManager($name);
            }
        }
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
            'target'                 => SecretRedactor::maskUrl($monitor->getTarget()),
            'config'                 => SecretRedactor::redactMonitorConfig($monitor->getConfig()),
            'interval_seconds'       => $monitor->getIntervalSeconds(),
            'retries'                => $monitor->getRetries(),
            'retry_interval_seconds' => $monitor->getRetryIntervalSeconds(),
            'paused'                 => $monitor->isPaused(),
            'project'                => $monitor->getProject(),
            'form'                   => [
                'url'                   => SecretRedactor::maskUrl($data->url),
                'method'                => $data->method,
                'expected_status_codes' => $data->expectedStatusCodes,
                'host'                  => $data->host,
                'port'                  => $data->port,
            ],
        ];
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
            $data->method = isset($config['method']) && is_string($config['method']) ? strtoupper($config['method']) : 'GET';
            if (isset($config['expected_status_codes']) && is_array($config['expected_status_codes'])) {
                $data->expectedStatusCodes = implode(',', array_map(strval(...), $config['expected_status_codes']));
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
