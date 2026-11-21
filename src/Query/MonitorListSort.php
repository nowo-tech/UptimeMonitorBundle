<?php

declare(strict_types=1);

namespace Nowo\UptimeMonitorBundle\Query;

/**
 * Sort key of {@see MonitorQueryInterface::list()} (since 1.35). Ties are always broken by name, then id, so a page
 * boundary is stable.
 */
enum MonitorListSort: string
{
    /** Problems first: down, degraded, not checked yet / unknown, up, then paused. */
    case Problem = 'problem';

    /** Monitor name. */
    case Name = 'name';

    /** Monitor type. */
    case Type = 'type';

    /** Check interval. */
    case Interval = 'interval';

    /** Database id (creation order). */
    case Id = 'id';
}
