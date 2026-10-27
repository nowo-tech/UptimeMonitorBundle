<?php

declare(strict_types=1);

namespace Nowo\UptimeMonitorBundle\Enum;

/**
 * Bucket size of a check rollup (hour, day, month, year).
 */
enum AggregatePeriod: string
{
    case Hour  = 'hour';
    case Day   = 'day';
    case Month = 'month';
    case Year  = 'year';
}
