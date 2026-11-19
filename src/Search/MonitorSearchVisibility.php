<?php

declare(strict_types=1);

namespace Nowo\UptimeMonitorBundle\Search;

use Nowo\UptimeMonitorBundle\Entity\Monitor;
use Nowo\UptimeMonitorBundle\Security\MonitorAwareUptimeMonitorAccessCheckerInterface;
use Nowo\UptimeMonitorBundle\Security\TenantAwareUptimeMonitorAccessCheckerInterface;
use Nowo\UptimeMonitorBundle\Security\UptimeMonitorAccessCheckerInterface;
use stdClass;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;

use function is_object;

/**
 * Whether the current viewer may see a monitor in search (since 1.32), decided by the bundle's own access checker as
 * the monitor page is: monitor-aware `canViewMonitor()`, else tenant-aware `canAccessTenant()`, else
 * `canAccessDashboard()`. `security.allow_unauthenticated` skips the checks. The user is read from the token storage
 * at call time and nothing is kept between calls: safe in worker mode.
 */
final readonly class MonitorSearchVisibility
{
    public function __construct(
        private UptimeMonitorAccessCheckerInterface $accessChecker,
        private ?TokenStorageInterface $tokenStorage = null,
        private bool $allowUnauthenticated = false,
    ) {
    }

    /**
     * Whether the viewer may see the monitor listed (and its status: the same check).
     */
    public function canSee(Monitor $monitor): bool
    {
        if ($this->allowUnauthenticated) {
            return true;
        }

        $user    = $this->user();
        $checker = $this->accessChecker;

        return match (true) {
            $checker instanceof MonitorAwareUptimeMonitorAccessCheckerInterface => $checker->canViewMonitor($user, $monitor),
            $checker instanceof TenantAwareUptimeMonitorAccessCheckerInterface  => $checker->canAccessTenant($user, $monitor->getTenant()->getSlug()),
            default                                                             => $checker->canAccessDashboard($user),
        };
    }

    private function user(): object
    {
        $user = $this->tokenStorage?->getToken()?->getUser();

        return is_object($user) ? $user : new stdClass();
    }
}
