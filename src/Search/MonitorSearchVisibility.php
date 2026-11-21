<?php

declare(strict_types=1);

namespace Nowo\UptimeMonitorBundle\Search;

use Nowo\UptimeMonitorBundle\Entity\Monitor;
use Nowo\UptimeMonitorBundle\Event\MonitorSearchAccessEvent;
use Nowo\UptimeMonitorBundle\Security\MonitorAwareUptimeMonitorAccessCheckerInterface;
use Nowo\UptimeMonitorBundle\Security\MonitorViewDecision;
use Nowo\UptimeMonitorBundle\Security\SearchAwareUptimeMonitorAccessCheckerInterface;
use Nowo\UptimeMonitorBundle\Security\TenantAwareUptimeMonitorAccessCheckerInterface;
use Nowo\UptimeMonitorBundle\Security\UptimeMonitorAccessCheckerInterface;
use Psr\EventDispatcher\EventDispatcherInterface;
use stdClass;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;

use function is_object;

/**
 * Whether the current viewer may see a monitor in search (since 1.32), decided by the bundle's own access checker as
 * the monitor page is: monitor-aware `canViewMonitor()`, else tenant-aware `canAccessTenant()`, else
 * `canAccessDashboard()`. Since 1.34 the search permission is its own check ({@see self::canSearchTenant()}).
 * `security.allow_unauthenticated` skips the checks. The user is read from the token storage
 * at call time and nothing is kept between calls: safe in worker mode.
 */
final readonly class MonitorSearchVisibility
{
    /**
     * Creates the check.
     *
     * @param UptimeMonitorAccessCheckerInterface $accessChecker Access checker of the admin UI
     * @param TokenStorageInterface|null $tokenStorage Token storage (null without SecurityBundle)
     * @param bool $allowUnauthenticated `security.allow_unauthenticated`: the checks are skipped
     * @param EventDispatcherInterface|null $eventDispatcher Dispatches {@see MonitorSearchAccessEvent} (since 1.34)
     */
    public function __construct(
        private UptimeMonitorAccessCheckerInterface $accessChecker,
        private ?TokenStorageInterface $tokenStorage = null,
        private bool $allowUnauthenticated = false,
        private ?EventDispatcherInterface $eventDispatcher = null,
    ) {
    }

    /**
     * The 1.32 search gate: `canAccessDashboard()` (always true under `security.allow_unauthenticated`).
     *
     * @deprecated since 1.34, the search gate is {@see self::canSearchTenant()}; this method is no longer called
     */
    public function canSearch(): bool
    {
        return $this->allowUnauthenticated || $this->accessChecker->canAccessDashboard($this->user());
    }

    /**
     * Whether the viewer may search the monitors of a tenant (since 1.34): the checker's
     * {@see SearchAwareUptimeMonitorAccessCheckerInterface::canSearch()} (denied when it does not implement it), then
     * {@see MonitorSearchAccessEvent} (a listener grants or denies; a denial wins). Always true under
     * `security.allow_unauthenticated`. The dashboard permission plays no part.
     */
    public function canSearchTenant(string $tenantSlug): bool
    {
        if ($this->allowUnauthenticated) {
            return true;
        }

        $user    = $this->user();
        $checker = $this->accessChecker;
        $event   = new MonitorSearchAccessEvent($user, $tenantSlug, $checker instanceof SearchAwareUptimeMonitorAccessCheckerInterface && $checker->canSearch($user, $tenantSlug));
        $this->eventDispatcher?->dispatch($event);

        return $event->isAllowed();
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
            $checker instanceof MonitorAwareUptimeMonitorAccessCheckerInterface => MonitorViewDecision::canView($checker, $user, $monitor),
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
