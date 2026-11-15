<?php

declare(strict_types=1);

namespace Nowo\UptimeMonitorBundle\Doctrine;

use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Query;
use Nowo\UptimeMonitorBundle\Transaction\UptimeTransactionRunner;

/**
 * 1.29 (Beacon spec 239): the bundle row a unit of work writes, locked and read again.
 *
 * Inside a transaction only (a unit of work of {@see UptimeTransactionRunner}).
 * The row is read with `SELECT … FOR UPDATE` (no lock on SQLite, where a writing transaction locks the whole
 * database) and its managed entity is refreshed from it, so the unit works on the state committed by every writer
 * before it, and the writers of the row queue up until the transaction ends. Lock order of the bundle: tenant row (quota
 * writes, the monitors file), then monitor row, then that monitor's results, aggregates and incidents.
 *
 * Stateless.
 *
 * @internal
 */
final class UptimeRowLock
{
    /**
     * @template T of object
     *
     * @param EntityManagerInterface $entityManager The manager of the row (the bundle's private one)
     * @param class-string<T> $class Bundle entity class
     * @param int $id Row id
     *
     * @return T|null The managed, locked and refreshed entity; null when the row does not exist (any more)
     */
    public static function lock(EntityManagerInterface $entityManager, string $class, int $id): ?object
    {
        /** @var T|null $entity */
        $entity = $entityManager->createQueryBuilder()
            ->select('e')
            ->from($class, 'e')
            ->where('e.id = :id')
            ->setParameter('id', $id)
            ->getQuery()
            ->setLockMode(LockMode::PESSIMISTIC_WRITE)
            // A row the manager already holds is overwritten with the locked state.
            ->setHint(Query::HINT_REFRESH, true)
            ->getOneOrNullResult();

        return $entity;
    }
}
