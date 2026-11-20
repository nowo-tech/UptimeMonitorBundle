<?php

declare(strict_types=1);

namespace Nowo\UptimeMonitorBundle\Event;

use function array_diff_key;
use function array_fill_keys;
use function array_filter;
use function array_intersect_key;
use function array_keys;
use function array_map;
use function array_values;

/**
 * The tenants a monitor search covers (since 1.34, {@see UptimeEvents::MONITOR_SEARCH_SCOPE}), dispatched once per
 * search before anything is read. It starts from the tenant keys of `search_kit.tenant_resolver`. A listener can only
 * narrow: {@see self::onlyTenants()} keeps the given keys (a second call keeps what both name),
 * {@see self::excludeTenants()} leaves some out, {@see self::deny()} leaves everything out. Keys are compared exactly.
 *
 * The scope id of the search box ({@see self::getScope()}) is client input. The bundle applies its own scope
 * `tenant:<slug>` itself; any other scope (a project chip, for instance) counts only when a listener narrows the event
 * for it and calls {@see self::acceptScope()}, else the search finds nothing. A scope never widens the tenants.
 *
 * Lives for one search; nothing is kept between requests (FrankenPHP worker mode).
 */
final class MonitorSearchScopeEvent
{
    /** @var array<string, true>|null */
    private ?array $only = null;

    /** @var array<string, true> */
    private array $excluded = [];

    private bool $denied = false;

    private bool $scopeAccepted = false;

    /**
     * @param string $userIdentifier The viewer's identifier
     * @param list<string> $tenants The tenant keys of the tenant resolver
     * @param string|null $scope The scope id of the search box (client input), or null
     */
    public function __construct(
        public readonly string $userIdentifier,
        private readonly array $tenants,
        private readonly ?string $scope = null,
    ) {
    }

    /**
     * The tenant keys the tenant resolver gave (before any listener).
     *
     * @return list<string>
     */
    public function getResolvedTenants(): array
    {
        return $this->tenants;
    }

    /**
     * The scope id of the search box, or null: client input, as typed.
     */
    public function getScope(): ?string
    {
        return $this->scope;
    }

    /**
     * Searches only these tenant keys (never more than the resolver gave). A second call keeps the keys both name.
     *
     * @param list<string> $tenantKeys
     */
    public function onlyTenants(array $tenantKeys): void
    {
        $given = $this->set($tenantKeys);
        // @igor-ignore - Request-scoped event object; not a shared worker service.
        $this->only = $this->only === null ? $given : array_intersect_key($this->only, $given);
    }

    /**
     * Leaves these tenant keys out.
     *
     * @param list<string> $tenantKeys
     */
    public function excludeTenants(array $tenantKeys): void
    {
        // @igor-ignore - Request-scoped event object; not a shared worker service.
        $this->excluded += $this->set($tenantKeys);
    }

    /**
     * Searches nothing for this viewer.
     */
    public function deny(): void
    {
        // @igor-ignore - Request-scoped event object; not a shared worker service.
        $this->denied = true;
    }

    /**
     * Says that this listener applied the scope of the search box by narrowing the event (e.g. onlyTenants() for a
     * project chip). A scope other than `tenant:<slug>` that no listener accepts finds nothing.
     */
    public function acceptScope(): void
    {
        // @igor-ignore - Request-scoped event object; not a shared worker service.
        $this->scopeAccepted = true;
    }

    /**
     * Whether a listener accepted the scope.
     */
    public function isScopeAccepted(): bool
    {
        return $this->scopeAccepted;
    }

    /**
     * The tenant keys to search after the listeners: the resolver's, narrowed (empty when denied).
     *
     * @return list<string>
     */
    public function getTenants(): array
    {
        if ($this->denied) {
            return [];
        }
        $keys = $this->set($this->tenants);
        if ($this->only !== null) {
            $keys = array_intersect_key($keys, $this->only);
        }

        return array_map(strval(...), array_keys(array_diff_key($keys, $this->excluded)));
    }

    /**
     * @param list<string> $values
     *
     * @return array<string, true>
     */
    private function set(array $values): array
    {
        return array_fill_keys(array_values(array_filter($values, static fn (string $value): bool => $value !== '')), true);
    }
}
