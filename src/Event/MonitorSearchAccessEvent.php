<?php

declare(strict_types=1);

namespace Nowo\UptimeMonitorBundle\Event;

use Nowo\UptimeMonitorBundle\Security\SearchAwareUptimeMonitorAccessCheckerInterface;

/**
 * Whether a viewer may search the monitors of one tenant (since 1.33, {@see UptimeEvents::MONITOR_SEARCH_ACCESS}),
 * dispatched once per tenant of a search. It starts from the access checker's answer
 * ({@see SearchAwareUptimeMonitorAccessCheckerInterface::canSearch()}; denied when the checker does not implement it).
 * A listener {@see self::grant()}s or {@see self::deny()}s; a denial wins over any grant. Granting search never shows
 * a monitor the viewer may not view: every row is still checked one by one.
 *
 * Lives for one call; nothing is kept between requests (FrankenPHP worker mode).
 */
final class MonitorSearchAccessEvent
{
    private bool $denied = false;

    /**
     * @param object $user The viewer (the security token's user, or an empty object without one)
     * @param string $tenantSlug The tenant searched
     * @param bool $allowed The access checker's answer
     */
    public function __construct(
        public readonly object $user,
        public readonly string $tenantSlug,
        private bool $allowed,
    ) {
    }

    /**
     * Lets the viewer search this tenant (unless a listener denies it).
     */
    public function grant(): void
    {
        // @igor-ignore - Request-scoped event object; not a shared worker service.
        $this->allowed = true;
    }

    /**
     * Refuses the search of this tenant, whatever any other listener grants.
     */
    public function deny(): void
    {
        // @igor-ignore - Request-scoped event object; not a shared worker service.
        $this->denied = true;
    }

    /**
     * Whether the viewer may search this tenant.
     */
    public function isAllowed(): bool
    {
        return $this->allowed && !$this->denied;
    }
}
