<?php

declare(strict_types=1);

namespace Nowo\UptimeMonitorBundle\Service;

use DateTimeImmutable;
use Nowo\UptimeMonitorBundle\Repository\MonitorRepository;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\Clock\NativeClock;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Executes all monitors that are due at the given time.
 */
final readonly class DueChecksRunner
{
    public function __construct(
        private MonitorRepository $monitorRepository,
        private CheckExecutorService $checkExecutor,
        #[Autowire('%nowo_uptime_monitor.enabled%')]
        private bool $enabled = true,
        private ClockInterface $clock = new NativeClock(),
    ) {
    }

    /**
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
        $count = 0;

        foreach ($this->monitorRepository->findDue($now) as $monitor) {
            $this->checkExecutor->execute($monitor);
            ++$count;
        }

        return $count;
    }
}
