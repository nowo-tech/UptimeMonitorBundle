<?php

declare(strict_types=1);

namespace Nowo\UptimeMonitorBundle\Repository;

use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Nowo\UptimeMonitorBundle\Doctrine\UptimeRowLock;
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
     * The tenant row locked for the running unit of work and read again (since 1.29, Beacon spec 239): the writers
     * of the row queue up until the transaction ends. Inside a transaction only.
     *
     * @return Tenant|null Null when the row does not exist (any more)
     */
    public function lockForWrite(int $id): ?Tenant
    {
        return UptimeRowLock::lock($this->getEntityManager(), Tenant::class, $id);
    }

    /**
     * Finds the tenant with this slug, if any.
     */
    public function findOneBySlug(string $slug): ?Tenant
    {
        return $this->findOneBy(['slug' => $slug]);
    }
}
