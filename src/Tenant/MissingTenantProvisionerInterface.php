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
 *
 * The slug always matches `TenantFormType::SLUG_PATTERN` (anything else answers 404 first) and the request is a safe
 * one: a POST never provisions. It does run on GET for every user the checker lets read, read-only users included, so
 * a checker that ignores the slug lets them create tenants by URL: decide here who may create what, and return null
 * otherwise. With `security.allow_unauthenticated: true` there is no access check before it.
 */
interface MissingTenantProvisionerInterface
{
    /**
     * The tenant of `$tenantSlug`, created now; null when there should be none.
     */
    public function provision(string $tenantSlug): ?Tenant;
}
