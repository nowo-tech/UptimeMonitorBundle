<?php

declare(strict_types=1);

namespace Nowo\UptimeMonitorBundle\Event;

/**
 * Names of the bundle events a host listens to by name (since 1.21). Each name is the event's class, so
 * `#[AsEventListener]` on a method typed with the event class works as well.
 */
final class UptimeEvents
{
    /** {@see MonitorQuotaCheckEvent}: before a monitor is created or resumed from the admin UI; cancellable. */
    public const MONITOR_QUOTA_CHECK = MonitorQuotaCheckEvent::class;
}
