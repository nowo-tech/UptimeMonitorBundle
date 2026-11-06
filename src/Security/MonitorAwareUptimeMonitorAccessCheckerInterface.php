<?php

declare(strict_types=1);

namespace Nowo\UptimeMonitorBundle\Security;

use Nowo\UptimeMonitorBundle\Entity\Monitor;

/**
 * Monitor-level read access (since 1.21): when the configured checker implements this interface, the reads of one
 * monitor — its page (`nowo_uptime_monitor_show`) and its history and aggregates APIs — are decided by
 * {@see canViewMonitor()} instead of {@see canAccessTenant()}, and a refusal answers 404 (the monitor's existence is
 * not revealed). Tenant-wide reads (dashboard, summary, status API) and every mutation keep the tenant methods.
 *
 * Typical use: monitors shared with users outside the tenant — `canAccessTenant()` stays false for them,
 * `canViewMonitor()` returns `canAccessTenant($user, $monitor->getTenant()->getSlug()) || <shared with $user>`.
 */
interface MonitorAwareUptimeMonitorAccessCheckerInterface extends TenantAwareUptimeMonitorAccessCheckerInterface
{
    /**
     * Whether the user may read this monitor (its page, history and aggregates).
     */
    public function canViewMonitor(object $user, Monitor $monitor): bool;
}
