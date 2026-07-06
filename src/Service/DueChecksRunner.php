<?php

declare(strict_types=1);

namespace Nowo\UptimeMonitorBundle\Service;

use DateTimeImmutable;
use Nowo\UptimeMonitorBundle\Repository\MonitorRepository;

/**
 * Executes all monitors that are due at the given time.
 */
final class DueChecksRunner
{
    public function __construct(
        private readonly MonitorRepository $monitorRepository,
        private readonly CheckExecutorService $checkExecutor,
    ) {
    }

    public function runDueChecks(DateTimeImmutable $now = new DateTimeImmutable()): int
    {
        $count = 0;

        foreach ($this->monitorRepository->findDue($now) as $monitor) {
            $this->checkExecutor->execute($monitor);
            ++$count;
        }

        return $count;
    }
}
