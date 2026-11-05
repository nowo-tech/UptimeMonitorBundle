<?php

declare(strict_types=1);

namespace Nowo\UptimeMonitorBundle\Apply;

use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Nowo\UptimeMonitorBundle\Entity\Monitor;
use Nowo\UptimeMonitorBundle\Entity\Tenant;
use Nowo\UptimeMonitorBundle\Enum\MonitorType;
use Nowo\UptimeMonitorBundle\Event\MonitorsAppliedEvent;
use Nowo\UptimeMonitorBundle\Limits\TenantQuota;
use Nowo\UptimeMonitorBundle\Push\PushThresholds;
use Nowo\UptimeMonitorBundle\Repository\MonitorRepository;
use Nowo\UptimeMonitorBundle\Repository\TenantRepository;
use Psr\EventDispatcher\EventDispatcherInterface;
use Throwable;

use function array_keys;
use function count;
use function hash_equals;
use function is_int;
use function is_string;
use function ksort;
use function max;

/**
 * Reconciles the push monitors of a monitors file with the database (since 1.18): create, update or leave unchanged
 * each entry, and list or delete (`--prune`) the file monitors the file no longer names. UI monitors are never read
 * for update, never adopted and never pruned.
 *
 * All or nothing: the tenants are resolved first, then one transaction locks every tenant row (slug order), plans
 * against the locked state, refuses on any conflict, writes and flushes. A database failure is rolled back and
 * replaced by {@see ApplyFailedException}, which keeps only the failure's class. A {@see MonitorsAppliedEvent}
 * listener that throws after the commit becomes a result warning, not a failure.
 */
final readonly class MonitorApplier
{
    private const TOKEN_SET       = 'set';
    private const TOKEN_ROTATED   = 'rotated';
    private const TOKEN_UNCHANGED = 'unchanged';
    private const TOKEN_KEPT      = 'kept';
    private const TOKEN_ABSENT    = 'absent';
    /** A {@see MonitorsAppliedEvent} listener threw after the commit (the run stays applied, exit 0). */
    public const WARNING_LISTENER_FAILED = 'event_listener_failed';

    /**
     * @param TenantQuota|null $quota Tenant limits (monitor count and minimum interval)
     * @param EventDispatcherInterface|null $eventDispatcher Receives {@see MonitorsAppliedEvent} after the commit
     */
    public function __construct(
        private EntityManagerInterface $entityManager,
        private MonitorRepository $monitors,
        private TenantRepository $tenants,
        private ?TenantQuota $quota = null,
        private ?EventDispatcherInterface $eventDispatcher = null,
    ) {
    }

    /**
     * Applies (or, with `$dryRun`, plans) the file.
     *
     * Every refusal of the run is collected before it is reported: a missing tenant (exit 5) is listed together with
     * the conflicts of the tenants that exist (exit 6). Any other failure, from the first tenant lookup to the commit,
     * is replaced by {@see ApplyFailedException}: a database message may quote the bound parameters (the token hash).
     *
     * @throws ApplyRefusedException exit 4–6, nothing written
     * @throws ApplyFailedException the run failed and was rolled back
     */
    public function apply(MonitorFileSpec $spec, ExternalPushTokens $tokens, bool $dryRun, bool $prune): ApplyResult
    {
        try {
            [$tenants, $missing] = $this->resolveTenants($spec);
            if ($dryRun || $missing !== []) {
                // No lock and no write: a dry run, or a run already refused (its conflicts are still reported).
                [$plan, $errors] = $this->plan($spec, $tenants, $tokens, $prune);
                if ($missing !== [] || $errors !== []) {
                    throw new ApplyRefusedException([...$missing, ...$errors]);
                }

                return $this->result($plan, $tenants, true, $prune);
            }
        } catch (ApplyRefusedException $refused) {
            throw $refused;
        } catch (Throwable $failure) {
            throw ApplyFailedException::from($failure);
        }

        // A plain transaction, not wrapInTransaction(): a refusal must leave the entity manager open (nothing was
        // written yet), and a failure is replaced, not rethrown.
        $connection = $this->entityManager->getConnection();
        $connection->beginTransaction();
        try {
            foreach ($tenants as $tenant) {
                $this->entityManager->lock($tenant, LockMode::PESSIMISTIC_WRITE);
            }
            // Planned again under the locks: a concurrent UI create may have taken a URL key or a quota slot.
            [$plan, $errors] = $this->plan($spec, $tenants, $tokens, $prune);
            if ($errors !== []) {
                throw new ApplyRefusedException($errors);
            }
            $this->write($plan, $prune);
            $this->entityManager->flush();
            $connection->commit();
        } catch (Throwable $failure) {
            if ($connection->isTransactionActive()) {
                $connection->rollBack();
            }

            throw $failure instanceof ApplyRefusedException ? $failure : ApplyFailedException::from($failure);
        }

        // The changes are committed: a failing listener must not turn the run into "nothing applied", nor deprive the
        // later tenants of their event. Its failure is reported as a warning (exit 0) with its class only.
        $result = $this->result($plan, $tenants, false, $prune);
        foreach (array_keys($tenants) as $slug) {
            $entries = $result->forTenant($slug);
            try {
                $this->eventDispatcher?->dispatch(new MonitorsAppliedEvent($slug, $entries['created'], $entries['updated'], $entries['unchanged'], $entries['pruned']));
            } catch (Throwable $listenerFailure) {
                $result = $result->withWarning($slug, self::WARNING_LISTENER_FAILED, $listenerFailure::class);
            }
        }

        return $result;
    }

    /**
     * The tenants of the file that exist, and a `tenant_not_found` error (exit 5) for each one that does not (it is
     * never created).
     *
     * @return array{0: array<string, Tenant>, 1: list<ApplyError>} slug => tenant in slug order, and the errors
     */
    private function resolveTenants(MonitorFileSpec $spec): array
    {
        $tenants = [];
        $errors  = [];
        foreach (array_keys($spec->tenants) as $slug) {
            $tenant = $this->tenants->findOneBySlug($slug);
            if ($tenant instanceof Tenant) {
                $tenants[$slug] = $tenant;
            } else {
                $errors[] = new ApplyError('/tenants/' . $slug, 'tenant_not_found', ApplyError::EXIT_TENANT);
            }
        }
        ksort($tenants);

        return [$tenants, $errors];
    }

    /**
     * @param array<string, Tenant> $tenants
     *
     * @return array{0: array{entries: list<array<string, mixed>>, prunes: list<array{tenant: string, key: string, id: int|null, monitor: Monitor}>}, 1: list<ApplyError>}
     */
    private function plan(MonitorFileSpec $spec, array $tenants, ExternalPushTokens $tokens, bool $prune): array
    {
        $entries = [];
        $prunes  = [];
        $errors  = [];
        foreach ($tenants as $slug => $tenant) {
            $existing = [];
            foreach ($this->monitors->findBy(['tenant' => $tenant, 'origin' => Monitor::ORIGIN_FILE]) as $monitor) {
                $existing[(string) $monitor->getOriginKey()] = $monitor;
            }
            $minInterval = $this->quota?->minIntervalSeconds($slug) ?? 30;
            $creates     = 0;
            foreach ($spec->tenants[$slug] as $entry) {
                $monitor = $existing[$entry->key] ?? null;
                unset($existing[$entry->key]);
                $planned = $this->planEntry($entry, $monitor, $tokens, $minInterval, $errors);
                $creates += $monitor === null ? 1 : 0;
                $entries[] = $planned + ['tenant' => $tenant, 'spec' => $entry, 'monitor' => $monitor];
            }
            foreach ($existing as $key => $monitor) {
                // The id is read now: Doctrine clears it once the row is deleted.
                $prunes[] = ['tenant' => $slug, 'key' => $key, 'id' => $monitor->getId(), 'monitor' => $monitor];
            }
            $remaining = $this->quota?->remaining($slug);
            if ($remaining !== null && $creates - ($prune ? count($existing) : 0) > $remaining) {
                $errors[] = new ApplyError('/tenants/' . $slug, 'over_quota', ApplyError::EXIT_CONFLICT);
            }
        }

        return [['entries' => $entries, 'prunes' => $prunes], $errors];
    }

    /**
     * @param list<ApplyError> $errors
     *
     * @return array{changed: list<string>, token: string, hash: string|null}
     */
    private function planEntry(PushMonitorSpec $entry, ?Monitor $monitor, ExternalPushTokens $tokens, int $minInterval, array &$errors): array
    {
        if ($monitor instanceof Monitor && $monitor->getType() !== MonitorType::Push) {
            $errors[] = new ApplyError($entry->path('type'), 'type_change_refused', ApplyError::EXIT_CONFLICT);
        }
        if ($entry->intervalSeconds < $minInterval) {
            $errors[] = new ApplyError($entry->path('interval_seconds'), 'below_tenant_minimum', ApplyError::EXIT_CONFLICT);
        }
        $holder = $this->monitors->findOneBy(['pushKey' => $entry->urlKey]);
        if ($holder instanceof Monitor && $holder !== $monitor) {
            $errors[] = new ApplyError($entry->path('url_key'), 'url_key_taken', ApplyError::EXIT_CONFLICT);
        }

        $stored = $monitor?->getPushTokenHash();
        $hash   = $tokens->hashFor($entry->key);
        if ($hash === null) {
            $token = $stored === null ? self::TOKEN_ABSENT : self::TOKEN_KEPT;
        } elseif ($stored !== null && hash_equals($stored, $hash)) {
            $token = self::TOKEN_UNCHANGED;
            $hash  = null;
        } else {
            $token = $stored === null ? self::TOKEN_SET : self::TOKEN_ROTATED;
            $owner = $this->monitors->findOneBy(['pushTokenHash' => $hash]);
            if ($owner instanceof Monitor && $owner !== $monitor) {
                $errors[] = new ApplyError('token-file:/' . $entry->key, 'token_reused', ApplyError::EXIT_TOKEN);
            }
        }

        $changed = [];
        if ($monitor instanceof Monitor) {
            $current = $this->state($monitor, $entry->paused !== null);
            foreach ($this->desired($entry) as $field => $value) {
                if ($current[$field] !== $value) {
                    $changed[] = $field;
                }
            }
            if ($hash !== null) {
                $changed[] = 'token';
            }
        }

        return ['changed' => $changed, 'token' => $token, 'hash' => $hash];
    }

    /**
     * @param array{entries: list<array<string, mixed>>, prunes: list<array{tenant: string, key: string, id: int|null, monitor: Monitor}>} $plan
     */
    private function write(array &$plan, bool $prune): void
    {
        foreach ($plan['entries'] as $i => $entry) {
            /** @var PushMonitorSpec $spec */
            $spec = $entry['spec'];
            /** @var Monitor|null $monitor */
            $monitor = $entry['monitor'];
            /** @var list<string> $changed */
            $changed = $entry['changed'];
            if ($monitor instanceof Monitor && $changed === []) {
                continue;
            }
            if (!$monitor instanceof Monitor) {
                /** @var Tenant $tenant */
                $tenant  = $entry['tenant'];
                $monitor = (new Monitor($tenant, $spec->name, MonitorType::Push, 'push'))->markFileManaged($spec->key);
                $this->entityManager->persist($monitor);
                $plan['entries'][$i]['created'] = $monitor;
            }
            $this->applySpec($monitor, $spec);
            if (is_string($entry['hash'])) {
                $monitor->setPushTokenHash($entry['hash']);
            }
        }
        if ($prune) {
            foreach ($plan['prunes'] as $doomed) {
                $this->entityManager->remove($doomed['monitor']);
            }
        }
    }

    private function applySpec(Monitor $monitor, PushMonitorSpec $spec): void
    {
        $previous = $monitor->getConfig();
        $config   = [
            'push_signed'                    => false,
            'push_thresholds'                => $spec->thresholds,
            'resend_notification_after_down' => $spec->resendNotificationAfterDown,
            'retries'                        => $spec->retries,
            // A push monitor is judged once per interval: a breach within the retries is re-judged at the next one.
            'retry_interval_seconds' => max(30, $spec->intervalSeconds),
        ];
        if ($spec->graceSeconds !== null) {
            $config['push_grace_seconds'] = $spec->graceSeconds;
        }
        if ($spec->description !== '') {
            $config['description'] = $spec->description;
        }
        foreach (['failure_streak', 'down_notify_streak'] as $internal) {
            if (isset($previous[$internal])) {
                $config[$internal] = $previous[$internal];
            }
        }

        $monitor
            ->setName($spec->name)
            ->setProject($spec->project === '' ? null : $spec->project)
            ->setIntervalSeconds($spec->intervalSeconds)
            ->setConfig($config)
            ->setPushKey($spec->urlKey);
        if ($spec->paused !== null) {
            $monitor->setPaused($spec->paused);
        }
    }

    /**
     * The managed fields of an entry, keyed by their output names.
     *
     * @return array<string, mixed>
     */
    private function desired(PushMonitorSpec $spec): array
    {
        $state = [
            'name'                           => $spec->name,
            'project'                        => $spec->project,
            'description'                    => $spec->description,
            'url_key'                        => $spec->urlKey,
            'interval_seconds'               => $spec->intervalSeconds,
            'grace_seconds'                  => $spec->graceSeconds,
            'retries'                        => $spec->retries,
            'resend_notification_after_down' => $spec->resendNotificationAfterDown,
            'push_signed'                    => false,
        ];
        foreach ($spec->thresholds as $metric => $limits) {
            $state['thresholds.' . $metric] = $limits;
        }
        if ($spec->paused !== null) {
            $state['paused'] = $spec->paused;
        }

        return $state;
    }

    /**
     * The same fields read from a stored monitor.
     *
     * @return array<string, mixed>
     */
    private function state(Monitor $monitor, bool $withPaused): array
    {
        $config = $monitor->getConfig();
        $state  = [
            'name'                           => $monitor->getName(),
            'project'                        => $monitor->getProject() ?? '',
            'description'                    => is_string($config['description'] ?? null) ? $config['description'] : '',
            'url_key'                        => $monitor->getPushKey(),
            'interval_seconds'               => $monitor->getIntervalSeconds(),
            'grace_seconds'                  => is_int($config['push_grace_seconds'] ?? null) ? $config['push_grace_seconds'] : null,
            'retries'                        => $monitor->getRetries(),
            'resend_notification_after_down' => is_int($config['resend_notification_after_down'] ?? null) ? $config['resend_notification_after_down'] : 0,
            'push_signed'                    => ($config['push_signed'] ?? false) === true,
        ];
        foreach (PushThresholds::fromConfig($config)->limits as $metric => $limits) {
            $state['thresholds.' . $metric] = $limits;
        }
        if ($withPaused) {
            $state['paused'] = $monitor->isPaused();
        }

        return $state;
    }

    /**
     * @param array{entries: list<array<string, mixed>>, prunes: list<array{tenant: string, key: string, id: int|null, monitor: Monitor}>} $plan
     * @param array<string, Tenant> $tenants
     */
    private function result(array $plan, array $tenants, bool $dryRun, bool $prune): ApplyResult
    {
        $created   = [];
        $updated   = [];
        $unchanged = [];
        foreach ($plan['entries'] as $entry) {
            /** @var PushMonitorSpec $spec */
            $spec = $entry['spec'];
            /** @var Monitor|null $monitor */
            $monitor = $entry['monitor'] ?? $entry['created'] ?? null;
            /** @var list<string> $changed */
            $changed = $entry['changed'];
            /** @var string $token */
            $token = $entry['token'];
            $row   = ['tenant' => $spec->tenant, 'key' => $spec->key, 'id' => $monitor?->getId()];
            if ($entry['monitor'] === null) {
                $created[] = $row + ['token' => $token];
            } elseif ($changed !== []) {
                $updated[] = $row + ['changed' => $changed, 'token' => $token];
            } else {
                $unchanged[] = $row + ['token' => $token];
            }
        }
        $pruned = [];
        foreach ($plan['prunes'] as $doomed) {
            $pruned[] = ['tenant' => $doomed['tenant'], 'key' => $doomed['key'], 'id' => $doomed['id']];
        }
        $info = [];
        foreach ($tenants as $slug => $tenant) {
            $info[] = ['tenant' => $slug, 'tenant_enabled' => $tenant->isEnabled()];
        }

        return new ApplyResult($dryRun, $info, $created, $updated, $unchanged, $prune ? $pruned : [], $prune ? [] : $pruned);
    }
}
