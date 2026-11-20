<?php

declare(strict_types=1);

namespace Nowo\UptimeMonitorBundle\Search;

use Nowo\UptimeMonitorBundle\Event\MonitorSearchScopeEvent;
use Nowo\UptimeMonitorBundle\Form\TenantFormType;
use Psr\EventDispatcher\EventDispatcherInterface;

use function array_filter;
use function array_unique;
use function array_values;
use function in_array;
use function is_string;
use function preg_match;
use function str_starts_with;
use function strlen;
use function substr;

/**
 * The tenants one monitor search covers (since 1.33), in this order:
 *
 * 1. the tenant keys of `search_kit.tenant_resolver` (a list, or the single tenant of a 1.32 resolver); a key that is
 *    not a valid tenant slug (lowercase letters, digits, dashes) is dropped, so keys match slugs exactly;
 * 2. {@see MonitorSearchScopeEvent}: listeners narrow (onlyTenants, excludeTenants, deny) and may accept a host scope;
 * 3. the scope of the search box: `tenant:<slug>` keeps that tenant if it is still in the list; any other scope finds
 *    nothing unless a listener accepted it; a scope never adds a tenant;
 * 4. the search permission per tenant ({@see MonitorSearchVisibility::canSearchTenant()}).
 *
 * The result is applied in SQL by the definition (`t.slug IN (...)`). Stateless: resolved on every call.
 */
final readonly class MonitorSearchTenants
{
    /** Prefix of the bundle's own scope id: one tenant. */
    public const SCOPE_PREFIX = 'tenant:';

    /**
     * @param MonitorSearchTenantResolverInterface|MonitorSearchTenantsResolverInterface $resolver The tenant resolver
     * @param MonitorSearchVisibility $visibility The search permission
     * @param EventDispatcherInterface|null $eventDispatcher Dispatches {@see MonitorSearchScopeEvent}
     */
    public function __construct(
        private MonitorSearchTenantResolverInterface|MonitorSearchTenantsResolverInterface $resolver,
        private MonitorSearchVisibility $visibility,
        private ?EventDispatcherInterface $eventDispatcher = null,
    ) {
    }

    /**
     * The tenant slugs the viewer may search with this scope; empty: nothing.
     *
     * @return list<string>
     */
    public function resolve(string $userIdentifier, ?string $scope = null): array
    {
        $keys = array_values(array_unique(array_filter(
            $this->resolverKeys($userIdentifier),
            static fn (string $key): bool => preg_match(TenantFormType::SLUG_PATTERN, $key) === 1,
        )));
        if ($keys === []) {
            return [];
        }

        $event = new MonitorSearchScopeEvent($userIdentifier, $keys, $scope);
        $this->eventDispatcher?->dispatch($event);
        $keys = $event->getTenants();

        if ($scope !== null) {
            if (str_starts_with($scope, self::SCOPE_PREFIX)) {
                $wanted = substr($scope, strlen(self::SCOPE_PREFIX));
                $keys   = in_array($wanted, $keys, true) ? [$wanted] : [];
            } elseif (!$event->isScopeAccepted()) {
                return [];
            }
        }

        return array_values(array_filter($keys, $this->visibility->canSearchTenant(...)));
    }

    /**
     * @return list<string>
     */
    private function resolverKeys(string $userIdentifier): array
    {
        if ($this->resolver instanceof MonitorSearchTenantsResolverInterface) {
            return array_values(array_filter($this->resolver->resolveTenants($userIdentifier), is_string(...)));
        }
        $slug = $this->resolver->resolve($userIdentifier);

        return $slug === null ? [] : [$slug];
    }
}
