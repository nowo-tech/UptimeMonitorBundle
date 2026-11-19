<?php

declare(strict_types=1);

namespace Nowo\UptimeMonitorBundle\Search;

/**
 * Host hook `search_kit.tenant_resolver` (since 1.32): the tenant whose monitors a viewer searches. The palette request
 * carries no `{tenantSlug}` of its own, so a multi-tenant host says which tenant the viewer is in (e.g. the one of
 * its current project). Resolve it on each call and keep nothing: the service lives across requests in worker mode.
 * The tenant is only the scope of the SQL query: what the viewer may see inside it (or outside it, for a shared
 * monitor) is still decided by the access checker.
 */
interface MonitorSearchTenantResolverInterface
{
    /**
     * The tenant slug of the viewer, or null when there is none (the search then finds nothing).
     */
    public function resolve(string $userIdentifier): ?string;
}
