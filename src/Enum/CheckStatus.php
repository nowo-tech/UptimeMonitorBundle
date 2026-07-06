<?php

declare(strict_types=1);

namespace Nowo\UptimeMonitorBundle\Enum;

enum CheckStatus: string
{
    case Up       = 'up';
    case Down     = 'down';
    case Degraded = 'degraded';
    case Unknown  = 'unknown';
}
