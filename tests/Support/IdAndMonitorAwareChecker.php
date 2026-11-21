<?php

declare(strict_types=1);

namespace Nowo\UptimeMonitorBundle\Tests\Support;

use Nowo\UptimeMonitorBundle\Security\MonitorAwareUptimeMonitorAccessCheckerInterface;
use Nowo\UptimeMonitorBundle\Security\MonitorIdAwareUptimeMonitorAccessCheckerInterface;

/**
 * A host checker with the monitor-level reads in both forms (1.35 tests: mocks need one interface).
 */
interface IdAndMonitorAwareChecker extends MonitorAwareUptimeMonitorAccessCheckerInterface, MonitorIdAwareUptimeMonitorAccessCheckerInterface
{
}
