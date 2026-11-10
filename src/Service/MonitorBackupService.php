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
use Nowo\UptimeMonitorBundle\Event\MonitorQuotaCheckEvent;
use Nowo\UptimeMonitorBundle\Event\UptimeEvents;
use Nowo\UptimeMonitorBundle\Form\Model\MonitorFormData;
use Nowo\UptimeMonitorBundle\Limits\TenantQuota;
use Nowo\UptimeMonitorBundle\Monitor\SpkiPins;
use Nowo\UptimeMonitorBundle\Push\PushThresholds;
use Nowo\UptimeMonitorBundle\Repository\MonitorRepository;
use Nowo\UptimeMonitorBundle\Security\SecretRedactor;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\Clock\NativeClock;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\Validator\Validator\ValidatorInterface;
use Throwable;

use function ceil;
use function count;
use function in_array;
use function is_array;
use function is_int;
use function is_numeric;
use function is_string;
use function max;
use function sprintf;
use function strtoupper;

/**
 * Export/import monitors (Uptime Kuma backup JSON subset — no history).
 *
 * SEC-004 L7: exports redact secrets (URL / proxy userinfo, basic-auth password, custom header values); imports
 * accept at most {@see self::MAX_IMPORT_ROWS} rows, look names up once, and skip rows that fail the monitor form
 * constraints (counted as `invalid`). Export and import are logged with the tenant slug and counts (REQ-OBS-001).
 *
 * Tenant limits (SEC-004 Q1): with a {@see TenantQuota}, monitors (groups not counted) beyond the tenant's remaining
 * quota are not created — nor an existing group overwritten into a monitor — and are counted as `over_quota`; the
 * check interval of every imported or overwritten monitor is raised to the tenant minimum.
 *
 * Host quota (since 1.21): every row that would write dispatches {@see MonitorQuotaCheckEvent} (`create` for a new row
 * or a changed type, `resume` for an overwrite that resumes a paused monitor). A denied row is not written and is counted
 * as `quota_denied`, with the first reason in `quota_denied_reason`; the other rows go on. With a listener each written
 * row is flushed before the next check, so a listener that counts monitors sees it.
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
     * @param ManagerRegistry|null $registry Resets the bundle manager when a failed import flush closed it
     * @param ClockInterface $clock Clock for the export timestamp
     * @param ValidatorInterface|null $validator Validates import rows against the monitor form constraints
     * @param LoggerInterface $logger Receives export / import start, success (counts) and failure (exception class)
     * @param TenantQuota|null $quota Tenant limits (max monitors, minimum interval) applied to imported rows
     * @param EventDispatcherInterface|null $eventDispatcher Dispatches the host quota check of each row (since 1.21)
     */
    public function __construct(
        private MonitorRepository $monitorRepository,
        private MonitorFactory $monitorFactory,
        private EntityManagerInterface $entityManager,
        private ?ManagerRegistry $registry = null,
        private ClockInterface $clock = new NativeClock(),
        private ?ValidatorInterface $validator = null,
        private LoggerInterface $logger = new NullLogger(),
        private ?TenantQuota $quota = null,
        private ?EventDispatcherInterface $eventDispatcher = null,
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
     * @return array{imported: int, skipped: int, overwritten: int, invalid: int, over_quota: int, quota_denied: int, quota_denied_reason: string|null}
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

        try {
            // SEC-004 Q3: with a quota the rows are written under the tenant row lock, the remaining quota counted
            // once after the lock (null = no monitor limit); without one, a plain flush.
            $result = $this->quota instanceof TenantQuota
                ? $this->quota->withTenantLock($this->entityManager, $tenant, fn (?int $remaining): array => $this->importRows($tenant, $rows, $mode, $remaining))
                : $this->importRows($tenant, $rows, $mode, null);
        } catch (Throwable $e) {
            if ($this->registry instanceof ManagerRegistry && !$this->entityManager->isOpen()) {
                $this->resetClosedManagers($this->registry);
            }

            $this->logger->error('Uptime backup import failed for tenant {tenant}: {error}', $context + ['error' => $e::class]);

            throw $e;
        }

        $this->logger->info('Uptime backup imported for tenant {tenant}', $context + $result);

        return $result;
    }

    /**
     * Creates / overwrites the monitors of the backup rows and flushes them.
     *
     * @param array<mixed> $rows
     * @param int|null $remaining Monitors the tenant may still add (null = no limit)
     *
     * @return array{imported: int, skipped: int, overwritten: int, invalid: int, over_quota: int, quota_denied: int, quota_denied_reason: string|null}
     */
    private function importRows(Tenant $tenant, array $rows, string $mode, ?int $remaining): array
    {
        // One lookup for the whole import (name => monitor), not one query per row.
        $byName = [];
        foreach ($this->monitorRepository->findByTenantSlug($tenant->getSlug()) as $monitor) {
            $byName[$monitor->getName()] ??= $monitor;
        }

        $minInterval = $this->quota?->minIntervalSeconds($tenant->getSlug()) ?? 0;

        $imported    = 0;
        $skipped     = 0;
        $overwritten = 0;
        $invalid     = 0;
        $overQuota   = 0;
        $denied      = 0;
        $reason      = null;
        // No listener: no per-row flush, the import runs as before.
        $checked = $this->eventDispatcher?->hasListeners(UptimeEvents::MONITOR_QUOTA_CHECK) === true;

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

            $countsAgainstQuota = $data->type !== MonitorType::Group;
            if ($countsAgainstQuota) {
                $data->intervalSeconds = max($minInterval, $data->intervalSeconds);
            }

            $existing = $byName[$name] ?? null;
            if ($existing instanceof Monitor) {
                // A file-managed monitor (1.18) belongs to its monitors file: never overwritten by an import.
                if ($mode === self::IMPORT_OVERWRITE && !$existing->isFileManaged()) {
                    // Since 1.25.1: a masked secret of the export is not put back for another scheme, host or port.
                    if ($this->monitorFactory->secretsNeedingRetype($existing, $data) !== []) {
                        ++$invalid;
                        continue;
                    }
                    if ($checked && $this->isDenied(MonitorQuotaCheckEvent::forChange($tenant->getSlug(), $existing, $data->type, $data->paused), $reason)) {
                        ++$denied;
                        continue;
                    }
                    // A group (not counted) overwritten into a monitor takes one slot of the quota.
                    if ($countsAgainstQuota && $existing->isGroup() && !$this->takeSlot($remaining)) {
                        ++$overQuota;
                        continue;
                    }
                    $this->monitorFactory->applyFormData($existing, $data);
                    ++$overwritten;
                    if ($checked) {
                        $this->entityManager->flush();
                    }
                    continue;
                }

                ++$skipped;
                continue;
            }

            if ($checked && $this->isDenied([new MonitorQuotaCheckEvent(MonitorQuotaCheckEvent::ACTION_CREATE, $tenant->getSlug(), $data->type)], $reason)) {
                ++$denied;
                continue;
            }

            if ($countsAgainstQuota && !$this->takeSlot($remaining)) {
                ++$overQuota;
                continue;
            }

            $monitor = $this->monitorFactory->createFromFormData($tenant, $data);
            $this->entityManager->persist($monitor);
            $byName[$name] = $monitor;
            ++$imported;
            if ($checked) {
                $this->entityManager->flush();
            }
        }

        $this->entityManager->flush();

        return ['imported' => $imported, 'skipped' => $skipped, 'overwritten' => $overwritten, 'invalid' => $invalid, 'over_quota' => $overQuota, 'quota_denied' => $denied, 'quota_denied_reason' => $reason];
    }

    /**
     * Dispatches the checks of one row, stopping at the first denial; `$reason` keeps the first reason of the import.
     *
     * @param list<MonitorQuotaCheckEvent> $checks
     */
    private function isDenied(array $checks, ?string &$reason): bool
    {
        foreach ($checks as $check) {
            $this->eventDispatcher?->dispatch($check);
            if ($check->isDenied()) {
                $reason ??= $check->getReason();

                return true;
            }
        }

        return false;
    }

    /**
     * Takes one slot of the remaining quota; false when the tenant is full (null = no limit).
     */
    private function takeSlot(?int &$remaining): bool
    {
        if ($remaining === null) {
            return true;
        }
        if ($remaining <= 0) {
            return false;
        }
        --$remaining;

        return true;
    }

    /**
     * Resets the manager the import wrote through (1.25.3: only that one, never another closed manager of the host).
     */
    private function resetClosedManagers(ManagerRegistry $registry): void
    {
        foreach ($registry->getManagers() as $name => $manager) {
            if ($manager === $this->entityManager) {
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
            // Informational (1.18): an import ignores them and always creates UI monitors.
            'origin'     => $monitor->getOrigin(),
            'origin_key' => $monitor->getOriginKey(),
            'form'       => [
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

        // An export carries no request body (blanked): an overwrite never keeps or carries one (since 1.25.2, as before).
        $data->httpBodyClear = true;

        $config = $row['config'] ?? [];
        if (is_array($config)) {
            $data->url    = isset($config['url']) && is_string($config['url']) ? $config['url'] : (string) ($row['target'] ?? '');
            $data->method = isset($config['method']) && is_string($config['method']) ? strtoupper($config['method']) : 'GET';
            if (isset($config['expected_status_codes']) && is_array($config['expected_status_codes'])) {
                $data->expectedStatusCodes = implode(',', array_map(strval(...), $config['expected_status_codes']));
            }
            // The targets of the other types (1.18: they are required, and were left empty by earlier imports).
            $data->host             = is_string($config['host'] ?? null) ? $config['host'] : '';
            $data->port             = is_int($config['port'] ?? null) ? $config['port'] : $data->port;
            $data->hostname         = is_string($config['hostname'] ?? null) ? $config['hostname'] : '';
            $data->recordType       = is_string($config['record_type'] ?? null) ? $config['record_type'] : $data->recordType;
            $data->expectedDnsValue = is_string($config['expected_value'] ?? null) ? $config['expected_value'] : '';
            $data->daysBeforeExpiry = is_int($config['days_before_expiry'] ?? null) ? $config['days_before_expiry'] : $data->daysBeforeExpiry;
            // SPKI pins (1.20): a non-list value or a malformed entry becomes "?" and more than MAX_PINS entries stay
            // too many, so the validator refuses the row instead of importing it unpinned (GA-L1, GA-L2).
            $data->expectedSpkiPins = SpkiPins::toFormText($config['expected_spki_pins'] ?? null);
            $timeout                = $config['request_timeout_seconds'] ?? $config['timeout'] ?? null;
            if (is_numeric($timeout)) {
                $data->requestTimeoutSeconds = max(1, (int) ceil((float) $timeout));
            } elseif (in_array($data->type, [MonitorType::Tcp, MonitorType::Ssl, MonitorType::Ping], true)) {
                // What those checks used when nothing was stored (TCP / SSL 10 s, ping 5 s).
                $data->requestTimeoutSeconds = $data->type === MonitorType::Ping ? 5 : 10;
            }
            if ($data->type === MonitorType::Push) {
                // A push monitor keeps its thresholds and options; it gets a new key, and a token must be generated.
                $data->pushSigned       = ($config['push_signed'] ?? false) === true;
                $data->pushGraceSeconds = isset($config['push_grace_seconds']) && is_int($config['push_grace_seconds']) ? $config['push_grace_seconds'] : null;
                foreach (PushThresholds::fromConfig($config)->limits as $metric => [$warn, $critical]) {
                    $data->pushThresholds[$metric . 'Warn']     = $warn;
                    $data->pushThresholds[$metric . 'Critical'] = $critical;
                }
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
            if (isset($form['host']) && is_string($form['host']) && $form['host'] !== '') {
                $data->host = $form['host'];
            }
            if (isset($form['port']) && is_int($form['port'])) {
                $data->port = $form['port'];
            }
        }

        return $data;
    }
}
