<?php

declare(strict_types=1);

namespace Nowo\UptimeMonitorBundle\Service;

use Doctrine\ORM\EntityManagerInterface;
use InvalidArgumentException;
use Nowo\UptimeMonitorBundle\Entity\Tenant;
use Nowo\UptimeMonitorBundle\Form\TenantFormType;
use Nowo\UptimeMonitorBundle\Repository\TenantRepository;

use function preg_match;
use function sprintf;

/**
 * Host-managed tenants (since 1.9): a multi-tenant host creates, renames and disables its tenants itself — e.g. one per
 * project, slug = the project id — instead of the `/tenants` UI (set `tenants.list_enabled: false`).
 */
final readonly class TenantProvisioner
{
    /**
     * Creates the provisioner.
     *
     * @param TenantRepository $tenants Tenant lookup by slug
     * @param EntityManagerInterface $entityManager Persists created and changed tenants
     */
    public function __construct(
        private TenantRepository $tenants,
        private EntityManagerInterface $entityManager,
    ) {
    }

    /**
     * The tenant with this slug, created (enabled) when missing; an existing tenant keeps its settings.
     *
     * @throws InvalidArgumentException when the slug is not lowercase letters, digits and hyphens
     */
    public function ensure(string $slug, string $name): Tenant
    {
        // Same rule as the tenant form: the slug ends up in routes and Mercure topics (`{`/`}` are URI-template syntax).
        if (preg_match(TenantFormType::SLUG_PATTERN, $slug) !== 1) {
            throw new InvalidArgumentException(sprintf('Invalid tenant slug "%s": use lowercase letters, digits and hyphens.', $slug));
        }

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
