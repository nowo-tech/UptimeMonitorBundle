<?php

declare(strict_types=1);

namespace Nowo\UptimeMonitorBundle\Share;

/**
 * Host port (since 1.35): who a monitor may be shared with. The host implements it and names the service in
 * `nowo_uptime_monitor.shares.policy`; without one, {@see DenyAllMonitorSharePolicy} refuses every share (deny by
 * default). The bundle has already checked that the monitor exists in `$tenantSlug`.
 *
 * Whether the CURRENT user may manage the monitor's shares is the host's own access decision too (its controller, its
 * voter, or this policy reading its security token at call time: never a value kept from an earlier request).
 */
interface MonitorSharePolicyInterface
{
    /**
     * Whether the monitor `$monitorId` of the tenant `$tenantSlug` may be shared with the user `$userIdentifier`.
     */
    public function canShareWith(string $tenantSlug, int $monitorId, string $userIdentifier): bool;
}
