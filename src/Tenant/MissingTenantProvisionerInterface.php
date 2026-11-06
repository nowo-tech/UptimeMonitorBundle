<?php

declare(strict_types=1);

namespace Nowo\UptimeMonitorBundle\Tenant;

use Nowo\UptimeMonitorBundle\Entity\Tenant;
use Nowo\UptimeMonitorBundle\Service\TenantProvisioner;

/**
 * Host hook `multi_tenant.provisioner` (since 1.21): called when an admin page or API of a tenant slug that has no
 * tenant yet runs, after the access checks let the user in (kernel.controller, priority -8). Return the tenant of
 * that slug, persisted and flushed (e.g. through {@see TenantProvisioner::ensure()}),
 * or null when the slug names no tenant of the host: the request then answers 404.
 */
interface MissingTenantProvisionerInterface
{
    /**
     * The tenant of `$tenantSlug`, created now; null when there should be none.
     */
    public function provision(string $tenantSlug): ?Tenant;
}
