<?php

declare(strict_types=1);

namespace Nowo\UptimeMonitorBundle\Enum;

/**
 * Supported monitor check types.
 */
enum MonitorType: string
{
    case Http  = 'http';
    case Https = 'https';
    case Tcp   = 'tcp';
    case Ping  = 'ping';
    case Dns   = 'dns';
    case Ssl   = 'ssl';
    /** Project folder: aggregates child monitors and records its own heartbeat. */
    case Group = 'group';
}
