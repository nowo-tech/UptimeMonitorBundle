<?php

declare(strict_types=1);

namespace Nowo\UptimeMonitorBundle\Tests\Unit\Support;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Mapping\ClassMetadata;
use Doctrine\ORM\Query;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;
use Nowo\UptimeMonitorBundle\Entity\Monitor;

trait DoctrineQueryBuilderTrait
{
    /**
     * @param list<mixed> $result
     * @param class-string $entityClass
     */
    protected function createManagerRegistryWithQueryResult(
        array $result,
        string $entityClass = Monitor::class,
    ): ManagerRegistry {
        $query = $this->createMock(Query::class);
        $query->method('getResult')->willReturn($result);
        $query->method('getOneOrNullResult')->willReturn($result[0] ?? null);

        $qb = $this->createMock(QueryBuilder::class);
        $qb->method('select')->willReturnSelf();
        $qb->method('from')->willReturnSelf();
        $qb->method('innerJoin')->willReturnSelf();
        $qb->method('andWhere')->willReturnSelf();
        $qb->method('setParameter')->willReturnSelf();
        $qb->method('orderBy')->willReturnSelf();
        $qb->method('setMaxResults')->willReturnSelf();
        $qb->method('delete')->willReturnSelf();
        $qb->method('getQuery')->willReturn($query);

        $metadata = new ClassMetadata($entityClass);
        $em       = $this->createMock(EntityManagerInterface::class);
        $em->method('createQueryBuilder')->willReturn($qb);
        $em->method('getClassMetadata')->willReturn($metadata);

        $registry = $this->createMock(ManagerRegistry::class);
        $registry->method('getManager')->willReturn($em);
        $registry->method('getManagerForClass')->willReturn($em);

        return $registry;
    }

    /**
     * @return array{0: ManagerRegistry, 1: EntityManagerInterface, 2: QueryBuilder, 3: Query}
     */
    protected function createManagerRegistryWithMutableQuery(): array
    {
        $query = $this->createMock(Query::class);

        $qb = $this->createMock(QueryBuilder::class);
        $qb->method('select')->willReturnSelf();
        $qb->method('from')->willReturnSelf();
        $qb->method('innerJoin')->willReturnSelf();
        $qb->method('andWhere')->willReturnSelf();
        $qb->method('setParameter')->willReturnSelf();
        $qb->method('orderBy')->willReturnSelf();
        $qb->method('setMaxResults')->willReturnSelf();
        $qb->method('delete')->willReturnSelf();
        $qb->method('getQuery')->willReturn($query);

        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('createQueryBuilder')->willReturn($qb);

        $registry = $this->createMock(ManagerRegistry::class);
        $registry->method('getManager')->willReturn($em);
        $registry->method('getManagerForClass')->willReturn($em);

        return [$registry, $em, $qb, $query];
    }
}
