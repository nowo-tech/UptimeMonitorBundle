<?php

declare(strict_types=1);

namespace Nowo\UptimeMonitorBundle\Service;

use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use Nowo\UptimeMonitorBundle\Entity\Monitor;
use Nowo\UptimeMonitorBundle\Repository\MonitorRepository;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\Clock\NativeClock;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Throwable;

use function max;
use function sprintf;

/**
 * Executes all monitors that are due at the given time.
 *
 * Each monitor is isolated (SEC-004 M1): a monitor whose execution throws is logged (monitor id + exception class)
 * and skipped, the scan goes on with the next one. When the failure closed the entity manager (a flush that Doctrine could
 * not commit), the manager is reset through the registry, the failing monitor is rescheduled out-of-band (DQL UPDATE
 * of `next_check_at`, so it cannot head every following scan) and the remaining monitors are reloaded in the fresh
 * manager (SEC-004 N1). With `checks.max_scan_seconds` the scan stops once the budget is
 * spent; the remaining monitors stay due and run first on the next tick (oldest `next_check_at` first).
 */
final readonly class DueChecksRunner
{
    /**
     * @param MonitorRepository $monitorRepository Finds the due monitors of enabled tenants
     * @param CheckExecutorService $checkExecutor Runs and stores one check
     * @param bool $enabled `nowo_uptime_monitor.enabled` (false = no checks run)
     * @param ClockInterface $clock Reference clock of the scan and its budget
     * @param int $maxScanSeconds Time budget of one scan (`checks.max_scan_seconds`, default 300, 0 = none)
     * @param LoggerInterface $logger Receives isolated monitor failures and budget stops
     * @param ManagerRegistry|null $registry Resets a closed entity manager and reschedules the failing monitor
     */
    public function __construct(
        private MonitorRepository $monitorRepository,
        private CheckExecutorService $checkExecutor,
        #[Autowire('%nowo_uptime_monitor.enabled%')]
        private bool $enabled = true,
        private ClockInterface $clock = new NativeClock(),
        #[Autowire('%nowo_uptime_monitor.checks.max_scan_seconds%')]
        private int $maxScanSeconds = 300,
        private LoggerInterface $logger = new NullLogger(),
        private ?ManagerRegistry $registry = null,
    ) {
    }

    /**
     * Runs every due monitor once.
     *
     * @param DateTimeImmutable|null $now Reference time (defaults to the injected clock)
     *
     * @return int Number of executed checks (0 when nowo_uptime_monitor.enabled is false)
     */
    public function runDueChecks(?DateTimeImmutable $now = null): int
    {
        if (!$this->enabled) {
            return 0;
        }

        $now ??= $this->clock->now();
        $started = $this->clock->now()->getTimestamp();
        $count   = 0;
        $reset   = false;

        foreach ($this->monitorRepository->findDue($now) as $monitor) {
            if ($this->maxScanSeconds > 0 && $this->clock->now()->getTimestamp() - $started >= $this->maxScanSeconds) {
                $this->logger->warning('Uptime due-check scan stopped after {seconds}s budget; remaining monitors run next tick', [
                    'bundle'   => 'NowoUptimeMonitorBundle',
                    'seconds'  => $this->maxScanSeconds,
                    'executed' => $count,
                ]);
                break;
            }

            if ($reset) {
                // Entities of the closed manager are detached: continue with the copy of the fresh one.
                $monitor = $this->reload($monitor);
                if (!$monitor instanceof Monitor) {
                    continue;
                }
            }

            try {
                $this->checkExecutor->execute($monitor);
                ++$count;
            } catch (Throwable $e) {
                $this->logger->error('Uptime check of monitor {monitor_id} failed: {error}', [
                    'bundle'     => 'NowoUptimeMonitorBundle',
                    'monitor_id' => $monitor->getId(),
                    'error'      => $e::class,
                ]);
                $reset = $this->recoverClosedManager($monitor) || $reset;
            }
        }

        return $count;
    }

    /**
     * Resets the bundle entity manager when the failure closed it, then reschedules the failing monitor with a DQL
     * UPDATE (its in-memory next_check_at was never flushed). Returns whether the manager was reset.
     */
    private function recoverClosedManager(Monitor $monitor): bool
    {
        $em = $this->registry?->getManagerForClass(Monitor::class);
        if (!$this->registry instanceof ManagerRegistry || !$em instanceof EntityManagerInterface || $em->isOpen()) {
            return false;
        }

        foreach ($this->registry->getManagers() as $name => $manager) {
            if ($manager === $em) {
                $this->registry->resetManager($name);
            }
        }

        $fresh = $this->registry->getManagerForClass(Monitor::class);
        if ($fresh instanceof EntityManagerInterface && $monitor->getId() !== null) {
            $fresh->createQueryBuilder()
                ->update(Monitor::class, 'm')
                ->set('m.nextCheckAt', ':next')
                ->where('m.id = :id')
                ->setParameter('next', $this->clock->now()->modify(sprintf('+%d seconds', max(1, $monitor->getIntervalSeconds()))))
                ->setParameter('id', $monitor->getId())
                ->getQuery()
                ->execute();
        }

        $this->logger->warning('Uptime entity manager closed by monitor {monitor_id}: reset and monitor rescheduled', [
            'bundle'     => 'NowoUptimeMonitorBundle',
            'monitor_id' => $monitor->getId(),
        ]);

        return true;
    }

    private function reload(Monitor $monitor): ?Monitor
    {
        $em = $this->registry?->getManagerForClass(Monitor::class);

        return $em instanceof EntityManagerInterface && $monitor->getId() !== null ? $em->find(Monitor::class, $monitor->getId()) : null;
    }
}
