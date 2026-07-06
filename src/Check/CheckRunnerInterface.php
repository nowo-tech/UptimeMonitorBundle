<?php

declare(strict_types=1);

namespace Nowo\UptimeMonitorBundle\Check;

use Nowo\UptimeMonitorBundle\Entity\Monitor;
use Nowo\UptimeMonitorBundle\Model\CheckResultDto;

interface CheckRunnerInterface
{
    public function supports(Monitor $monitor): bool;

    public function run(Monitor $monitor): CheckResultDto;
}
