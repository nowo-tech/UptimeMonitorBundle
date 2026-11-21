<?php

declare(strict_types=1);

namespace Nowo\UptimeMonitorBundle\Query;

/**
 * Status filter of {@see MonitorListCriteria} (since 1.35). Every value but {@see self::Paused} lists only monitors
 * that are not paused; {@see self::Unknown} also matches a monitor that was never checked.
 */
enum MonitorListStatus: string
{
    case Up       = 'up';
    case Down     = 'down';
    case Degraded = 'degraded';
    case Unknown  = 'unknown';
    case Paused   = 'paused';
}
