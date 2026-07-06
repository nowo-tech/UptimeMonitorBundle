<?php

declare(strict_types=1);

namespace Nowo\UptimeMonitorBundle\Enum;

enum AggregatePeriod: string
{
    case Hour  = 'hour';
    case Day   = 'day';
    case Month = 'month';
    case Year  = 'year';
}
