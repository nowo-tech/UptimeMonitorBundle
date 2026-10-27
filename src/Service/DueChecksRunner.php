<?php

declare(strict_types=1);

namespace Nowo\UptimeMonitorBundle\Service;

use DateTimeImmutable;
use Nowo\UptimeMonitorBundle\Repository\MonitorRepository;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\Clock\NativeClock;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Throwable;

/**
 * Executes all monitors that are due at the given time.
 *
 * Each monitor is isolated (SEC-004 M1): a monitor whose execution throws is logged (monitor id + exception class)
 * and skipped, the scan goes on with the next one. With `checks.max_scan_seconds` the scan stops once the budget is
 * spent; the remaining monitors stay due and run first on the next tick (oldest `next_check_at` first).
 */
final readonly class DueChecksRunner
{
    /**
     * @param MonitorRepository $monitorRepository Finds the due monitors of enabled tenants
     * @param CheckExecutorService $checkExecutor Runs and stores one check
     * @param bool $enabled `nowo_uptime_monitor.enabled` (false = no checks run)
     * @param ClockInterface $clock Reference clock of the scan and its budget
     * @param int $maxScanSeconds Time budget of one scan (`checks.max_scan_seconds`, 0 = none)
     * @param LoggerInterface $logger Receives isolated monitor failures and budget stops
     */
    public function __construct(
        private MonitorRepository $monitorRepository,
        private CheckExecutorService $checkExecutor,
        #[Autowire('%nowo_uptime_monitor.enabled%')]
        private bool $enabled = true,
        private ClockInterface $clock = new NativeClock(),
        #[Autowire('%nowo_uptime_monitor.checks.max_scan_seconds%')]
        private int $maxScanSeconds = 0,
        private LoggerInterface $logger = new NullLogger(),
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

        foreach ($this->monitorRepository->findDue($now) as $monitor) {
            if ($this->maxScanSeconds > 0 && $this->clock->now()->getTimestamp() - $started >= $this->maxScanSeconds) {
                $this->logger->warning('Uptime due-check scan stopped after {seconds}s budget; remaining monitors run next tick', [
                    'bundle'   => 'NowoUptimeMonitorBundle',
                    'seconds'  => $this->maxScanSeconds,
                    'executed' => $count,
                ]);
                break;
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
            }
        }

        return $count;
    }
}
