<?php

declare(strict_types=1);

namespace Nowo\UptimeMonitorBundle\Service;

use Doctrine\ORM\EntityManagerInterface;
use Nowo\UptimeMonitorBundle\Entity\Tenant;
use Nowo\UptimeMonitorBundle\Repository\TenantRepository;

/**
 * Host-managed tenants (since 1.9): a multi-tenant host creates, renames and disables its tenants itself — e.g. one per
 * project, slug = the project id — instead of the `/tenants` UI (set `tenants.list_enabled: false`).
 */
final readonly class TenantProvisioner
{
    public function __construct(
        private TenantRepository $tenants,
        private EntityManagerInterface $entityManager,
    ) {
    }

    /** The tenant with this slug, created (enabled) when missing; an existing tenant keeps its settings. */
    public function ensure(string $slug, string $name): Tenant
    {
        $tenant = $this->tenants->findOneBySlug($slug);
        if (!$tenant instanceof Tenant) {
            $tenant = new Tenant($slug, $name);
            $this->entityManager->persist($tenant);
            $this->entityManager->flush();
        }

        return $tenant;
    }

    /** Enables or disables a tenant (a disabled tenant's checks stop); false when the slug is unknown. */
    public function setEnabled(string $slug, bool $enabled): bool
    {
        $tenant = $this->tenants->findOneBySlug($slug);
        if (!$tenant instanceof Tenant) {
            return false;
        }
        $tenant->setEnabled($enabled);
        $this->entityManager->flush();

        return true;
    }
}
