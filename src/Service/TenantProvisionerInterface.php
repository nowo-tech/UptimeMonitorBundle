<?php

declare(strict_types=1);

namespace Nowo\UptimeMonitorBundle\Service;

use InvalidArgumentException;
use Nowo\UptimeMonitorBundle\Entity\Tenant;

/**
 * Host-managed tenants (since 1.25 as an interface; {@see TenantProvisioner} is the implementation): a multi-tenant
 * host creates and disables its tenants itself, e.g. one per project with slug = the project id. Type-hint this
 * interface in host code instead of the final class.
 */
interface TenantProvisionerInterface
{
    /**
     * The tenant with this slug, created (enabled) and flushed when missing; an existing tenant keeps its settings.
     *
     * @param string $slug Lowercase letters, digits and hyphens
     * @param string $name Display name used when the tenant is created
     *
     * @throws InvalidArgumentException when the slug is not lowercase letters, digits and hyphens
     */
    public function ensure(string $slug, string $name): Tenant;

    /**
     * Enables or disables a tenant (a disabled tenant's checks stop); false when the slug is unknown.
     *
     * @param string $slug Tenant slug
     * @param bool $enabled New state
     */
    public function setEnabled(string $slug, bool $enabled): bool;
}
