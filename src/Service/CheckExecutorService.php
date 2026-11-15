<?php

declare(strict_types=1);

namespace Nowo\UptimeMonitorBundle\Service;

use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Nowo\TransactionContract\TransientStorageFailure;
use Nowo\UptimeMonitorBundle\Check\CheckRunnerInterface;
use Nowo\UptimeMonitorBundle\Doctrine\UptimeEntityManager;
use Nowo\UptimeMonitorBundle\Doctrine\UptimeRowLock;
use Nowo\UptimeMonitorBundle\Entity\CheckResult;
use Nowo\UptimeMonitorBundle\Entity\Monitor;
use Nowo\UptimeMonitorBundle\Enum\CheckStatus;
use Nowo\UptimeMonitorBundle\Enum\MonitorType;
use Nowo\UptimeMonitorBundle\Event\MonitorRecoveredEvent;
use Nowo\UptimeMonitorBundle\Event\MonitorWentDownEvent;
use Nowo\UptimeMonitorBundle\Model\CheckResultDto;
use Nowo\UptimeMonitorBundle\Notification\UptimeAlert;
use Nowo\UptimeMonitorBundle\Transaction\UptimeTransactionRunner;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\Clock\NativeClock;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;
use Throwable;

use function spl_object_id;
use function sprintf;

/**
 * Runs monitor checks and persists detail results.
 *
 * Since 1.29 (Beacon spec 239) a check is two steps:
 *
 * 1. the check itself (network, DNS, TLS), outside any transaction;
 * 2. one unit of work of the bundle's runner that locks the monitor row and reads it again, then applies the outcome
 *    to that committed state: retries and streaks, the next check time, the result row, the aggregate buckets and the
 *    incident. Concurrent checks of one monitor (a group refreshed by two children, two schedulers) queue on the row
 *    instead of counting a bucket twice, opening two incidents or failing on the bucket's unique key, and a check never
 *    writes back the configuration it loaded before the network step over an edit saved meanwhile. A deadlock or lock
 *    wait is replayed (up to {@see UptimeTransactionRunner::RETRY_ATTEMPTS}); the check is not run again.
 *
 * Side effects follow the commit: the incident events, the notifications, the dashboard sync and the refresh of the
 * parent groups (walked up with a visited set: a cycle in the data never loops).
 */
final readonly class CheckExecutorService
{
    /**
     * @param iterable<CheckRunnerInterface> $checkRunners
     * @param UptimeTransactionRunner|null $transactionRunner The bundle's runner (since 1.29; null = one on $entityManager)
     */
    public function __construct(
        #[AutowireIterator('nowo.uptime_monitor.check_runner')]
        private iterable $checkRunners,
        private EntityManagerInterface $entityManager,
        private AggregateService $aggregateService,
        private StatusTransitionService $statusTransitionService,
        private DashboardSyncDispatcher $dashboardSyncDispatcher,
        private CheckLatencyNormalizer $latencyNormalizer,
        private MonitorRetryService $monitorRetryService,
        private ClockInterface $clock = new NativeClock(),
        private ?UptimeTransactionRunner $transactionRunner = null,
    ) {
    }

    /**
     * Runs the monitor check, stores the result, aggregates, incidents and the next check time in one unit of work,
     * then dispatches the incident events (since 1.25), sends the notifications (since 1.29), notifies the dashboards
     * and refreshes the parent groups.
     *
     * @throws TransientStorageFailure When the storage still failed transiently after the retries (nothing stored)
     * @throws MonitorGoneException When the monitor was deleted while its check ran (since 1.29; nothing stored)
     */
    public function execute(Monitor $monitor): CheckResult
    {
        $result  = $this->executeOne($monitor);
        $visited = [spl_object_id($result->getMonitor()) => true];
        $parent  = $this->refreshableParent($result->getMonitor());
        // Iterative walk up the groups with a visited set: a group cycle in the data never recurses.
        while ($parent instanceof Monitor && !isset($visited[spl_object_id($parent)])) {
            $visited[spl_object_id($parent)] = true;
            try {
                $checked = $this->executeOne($parent)->getMonitor();
            } catch (MonitorGoneException) {
                break;
            }
            $parent = $this->refreshableParent($checked);
        }

        return $result;
    }

    private function executeOne(Monitor $monitor): CheckResult
    {
        // 1.27.0: a monitor the application loaded through its own manager is checked as the bundle manager's copy.
        if ($this->entityManager instanceof UptimeEntityManager) {
            $monitor = $this->entityManager->adopt($monitor);
        }
        // Remote data (DNS records, transport errors) is scrubbed to valid, bounded UTF-8 before anything stores it.
        $raw = $this->runCheck($monitor)->sanitized();
        $now = $this->clock->now();
        $id  = $monitor->getId();

        [$result, $events, $alerts] = $this->runner()->runRetrying(fn (): array => $this->store($id === null ? $monitor : null, $id, $raw, $now));

        // Since 1.25 the incident events follow the commit: the new incident has its id, a failed unit sends none.
        $this->statusTransitionService->dispatchCommitted($events);
        // Since 1.29 the notifications follow the commit too: a replayed unit never sends one twice.
        $this->statusTransitionService->sendCommitted($alerts);
        $this->dashboardSyncDispatcher->dispatchAfterCheck($result->getMonitor(), $result);

        return $result;
    }

    /**
     * The unit of work of one check: the monitor row locked and read again, then the outcome applied to it.
     *
     * @param Monitor|null $unsaved A monitor without an id (never stored: nothing to lock)
     *
     * @return array{0: CheckResult, 1: list<MonitorRecoveredEvent|MonitorWentDownEvent>, 2: list<UptimeAlert>}
     */
    private function store(?Monitor $unsaved, ?int $id, CheckResultDto $raw, DateTimeImmutable $now): array
    {
        $monitor = $unsaved ?? UptimeRowLock::lock($this->entityManager, Monitor::class, (int) $id) ?? throw new MonitorGoneException((int) $id);
        $dto     = $this->latencyNormalizer->normalizeDto(
            $monitor,
            $this->monitorRetryService->normalizeResult($monitor, $raw),
        );

        $result = new CheckResult(
            $monitor,
            $dto->status,
            $dto->latencyMs,
            $dto->statusCode,
            $dto->message,
            $dto->metadata,
            $now,
        );

        $this->entityManager->persist($result);
        $this->monitorRetryService->scheduleAfterCheck($monitor, $now, $raw);
        $this->aggregateService->recordFromCheck($monitor, $dto);
        [$events, $alerts] = $this->statusTransitionService->handleAfterCheckInUnit($monitor, $result);

        return [$result, $events, $alerts];
    }

    /**
     * The group to refresh after a check of the monitor: its parent, when it is an active group.
     */
    private function refreshableParent(Monitor $monitor): ?Monitor
    {
        $parent = $monitor->getParent();

        return $parent instanceof Monitor && $parent->getType() === MonitorType::Group && !$parent->isPaused() ? $parent : null;
    }

    /**
     * The bundle's runner of every write (since 1.29).
     */
    private function runner(): UptimeTransactionRunner
    {
        return $this->transactionRunner ?? new UptimeTransactionRunner($this->entityManager);
    }

    private function runCheck(Monitor $monitor): CheckResultDto
    {
        foreach ($this->checkRunners as $runner) {
            if ($runner->supports($monitor)) {
                try {
                    return $runner->run($monitor);
                } catch (Throwable $e) {
                    // A broken check is recorded (and the monitor rescheduled) instead of aborting the shared scan.
                    return new CheckResultDto(CheckStatus::Unknown, 0, null, sprintf('Check failed (%s)', $e::class));
                }
            }
        }

        return new CheckResultDto(
            CheckStatus::Unknown,
            0,
            null,
            sprintf('No check runner registered for type "%s"', $monitor->getType()->value),
        );
    }
}
