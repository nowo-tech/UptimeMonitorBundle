<?php

declare(strict_types=1);

namespace Nowo\UptimeMonitorBundle\Share;

/**
 * The default {@see MonitorSharePolicyInterface} (since 1.35): no monitor may be shared until the host names its own
 * policy in `shares.policy`. Stateless.
 */
final class DenyAllMonitorSharePolicy implements MonitorSharePolicyInterface
{
    /**
     * Never allows a share.
     */
    public function canShareWith(string $tenantSlug, int $monitorId, string $userIdentifier): bool
    {
        return false;
    }
}
