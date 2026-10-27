<?php

declare(strict_types=1);

namespace Nowo\UptimeMonitorBundle\Repository;

use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Nowo\UptimeMonitorBundle\Entity\Incident;
use Nowo\UptimeMonitorBundle\Entity\Monitor;

/**
 * @extends ServiceEntityRepository<Incident>
 */
class IncidentRepository extends ServiceEntityRepository
{
    /**
     * Creates the repository of incidents.
     *
     * @param ManagerRegistry $registry Doctrine registry
     */
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Incident::class);
    }

    /**
     * Finds the open (unresolved) incident of a monitor, if any.
     */
    public function findOpenForMonitor(Monitor $monitor): ?Incident
    {
        return $this->createQueryBuilder('i')
            ->andWhere('i.monitor = :monitor')
            ->andWhere('i.endedAt IS NULL')
            ->setParameter('monitor', $monitor)
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }
}
