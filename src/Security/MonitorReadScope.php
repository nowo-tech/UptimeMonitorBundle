<?php

declare(strict_types=1);

namespace Nowo\UptimeMonitorBundle\Security;

use Nowo\UptimeMonitorBundle\Entity\Monitor;
use stdClass;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;

use function is_object;

/**
 * What the monitor page shows a user under a {@see MonitorAwareUptimeMonitorAccessCheckerInterface} (since 1.21): a user
 * shared one monitor gets that monitor, not the rest of the tenant. Any other checker, or
 * `security.allow_unauthenticated`, narrows nothing: the page's own access check already decided. The user comes from
 * the token storage at call time: safe in worker mode.
 */
final readonly class MonitorReadScope
{
    /**
     * Creates the scope.
     *
     * @param UptimeMonitorAccessCheckerInterface $accessChecker Access checker of the admin UI
     * @param TokenStorageInterface|null $tokenStorage Token storage (null without SecurityBundle)
     * @param bool $allowUnauthenticated `security.allow_unauthenticated`: the checks are skipped
     */
    public function __construct(
        private UptimeMonitorAccessCheckerInterface $accessChecker,
        private ?TokenStorageInterface $tokenStorage = null,
        private bool $allowUnauthenticated = false,
    ) {
    }

    /**
     * Whether the user reads the tenant as a whole (the parent group's name, the push ingest URL).
     */
    public function seesTenant(string $tenantSlug): bool
    {
        $checker = $this->monitorAwareChecker();

        return !$checker instanceof MonitorAwareUptimeMonitorAccessCheckerInterface || $checker->canAccessTenant($this->user(), $tenantSlug);
    }

    /**
     * Whether the user may see this monitor listed (the children of a group).
     */
    public function canView(Monitor $monitor): bool
    {
        $checker = $this->monitorAwareChecker();

        return !$checker instanceof MonitorAwareUptimeMonitorAccessCheckerInterface || $checker->canViewMonitor($this->user(), $monitor);
    }

    private function monitorAwareChecker(): ?MonitorAwareUptimeMonitorAccessCheckerInterface
    {
        return !$this->allowUnauthenticated && $this->accessChecker instanceof MonitorAwareUptimeMonitorAccessCheckerInterface ? $this->accessChecker : null;
    }

    private function user(): object
    {
        $user = $this->tokenStorage?->getToken()?->getUser();

        return is_object($user) ? $user : new stdClass();
    }
}
