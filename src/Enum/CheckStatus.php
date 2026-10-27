<?php

declare(strict_types=1);

namespace Nowo\UptimeMonitorBundle\Enum;

/**
 * Outcome of one monitor check (up, down, degraded or unknown).
 */
enum CheckStatus: string
{
    case Up       = 'up';
    case Down     = 'down';
    case Degraded = 'degraded';
    case Unknown  = 'unknown';
}
