<?php

declare(strict_types=1);

namespace Nowo\UptimeMonitorBundle\Repository;

use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Nowo\UptimeMonitorBundle\Entity\Tenant;

/**
 * @extends ServiceEntityRepository<Tenant>
 */
class TenantRepository extends ServiceEntityRepository
{
    /**
     * Creates the repository of tenants.
     *
     * @param ManagerRegistry $registry Doctrine registry
     */
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Tenant::class);
    }

    /**
     * Finds the tenant with this slug, if any.
     */
    public function findOneBySlug(string $slug): ?Tenant
    {
        return $this->findOneBy(['slug' => $slug]);
    }
}
