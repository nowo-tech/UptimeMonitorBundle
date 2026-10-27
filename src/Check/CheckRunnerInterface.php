<?php

declare(strict_types=1);

namespace Nowo\UptimeMonitorBundle\Check;

use Nowo\UptimeMonitorBundle\Entity\Monitor;
use Nowo\UptimeMonitorBundle\Model\CheckResultDto;

/**
 * Runs one kind of monitor check (tagged `nowo.uptime_monitor.check_runner`).
 */
interface CheckRunnerInterface
{
    /**
     * Whether this runner handles the monitor type.
     */
    public function supports(Monitor $monitor): bool;

    /**
     * Runs the check now and returns its raw outcome (before retries, upside-down and latency normalisation).
     */
    public function run(Monitor $monitor): CheckResultDto;
}
