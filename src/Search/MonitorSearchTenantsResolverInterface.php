<?php

declare(strict_types=1);

namespace Nowo\UptimeMonitorBundle\Search;

/**
 * Host hook `search_kit.tenant_resolver` with several tenants (since 1.34): every tenant whose monitors a viewer
 * searches, e.g. the projects the viewer belongs to. The search covers their union; an empty list finds nothing. Keys
 * are tenant slugs, compared exactly (a key that is not a valid slug matches nothing). Resolve them on each call and
 * keep nothing: the service lives across requests in worker mode.
 *
 * A resolver of the single-tenant {@see MonitorSearchTenantResolverInterface} (1.32) still works: its tenant is a list
 * of one. Neither decides what the viewer sees inside a tenant: the search permission and every monitor are checked.
 */
interface MonitorSearchTenantsResolverInterface
{
    /**
     * The tenant slugs the viewer searches.
     *
     * @return list<string>
     */
    public function resolveTenants(string $userIdentifier): array;
}
