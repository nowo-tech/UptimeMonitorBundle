<?php

declare(strict_types=1);

namespace Nowo\UptimeMonitorBundle\Tests\Unit\Command;

use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Query;
use Doctrine\ORM\QueryBuilder;
use Nowo\UptimeMonitorBundle\Command\RollupCommand;
use Nowo\UptimeMonitorBundle\Entity\CheckResult;
use Nowo\UptimeMonitorBundle\Entity\Monitor;
use Nowo\UptimeMonitorBundle\Entity\Tenant;
use Nowo\UptimeMonitorBundle\Enum\CheckStatus;
use Nowo\UptimeMonitorBundle\Enum\MonitorType;
use Nowo\UptimeMonitorBundle\Repository\CheckAggregateRepository;
use Nowo\UptimeMonitorBundle\Tests\Unit\Support\DoctrineQueryBuilderTrait;
use Nowo\UptimeMonitorBundle\Tests\Unit\Support\EntityIdTrait;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * @covers \Nowo\UptimeMonitorBundle\Command\RollupCommand
 */
final class RollupCommandTest extends TestCase
{
    use DoctrineQueryBuilderTrait;
    use EntityIdTrait;

    public function testExecuteRebuildsAggregates(): void
    {
        $tenant  = new Tenant('main', 'Main');
        $monitor = new Monitor($tenant, 'API', MonitorType::Https, 'https://example.test');
        $this->setEntityId($monitor, 1);

        $result     = new CheckResult($monitor, CheckStatus::Up, 100, 200);
        $reflection = new ReflectionProperty($result, 'checkedAt');
        $reflection->setAccessible(true);
        $reflection->setValue($result, new DateTimeImmutable('-2 days'));

        $query = $this->createMock(Query::class);
        $query->method('getResult')->willReturn([$result]);

        $qb = $this->createMock(QueryBuilder::class);
        $qb->method('select')->willReturnSelf();
        $qb->method('from')->willReturnSelf();
        $qb->method('innerJoin')->willReturnSelf();
        $qb->method('andWhere')->willReturnSelf();
        $qb->method('setParameter')->willReturnSelf();
        $qb->method('orderBy')->willReturnSelf();
        $qb->method('getQuery')->willReturn($query);

        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('createQueryBuilder')->willReturn($qb);
        $em->expects(self::once())->method('flush');

        $registry            = $this->createManagerRegistryWithQueryResult([]);
        $aggregateRepository = $this->getMockBuilder(CheckAggregateRepository::class)
            ->onlyMethods(['findOneForBucket'])
            ->setConstructorArgs([$registry])
            ->getMock();
        $aggregateRepository->method('findOneForBucket')->willReturn(null);

        $command = new RollupCommand($em, $aggregateRepository, ['periods' => ['hour', 'bad', 'day']]);
        $tester  = new CommandTester($command);
        $tester->execute(['--days' => '7']);

        self::assertSame(0, $tester->getStatusCode());
        self::assertStringContainsString('Processed 1 check', $tester->getDisplay());
    }

    public function testExecuteUsesDefaultPeriodWhenConfigEmpty(): void
    {
        $query = $this->createMock(Query::class);
        $query->method('getResult')->willReturn([]);

        $qb = $this->createMock(QueryBuilder::class);
        $qb->method('select')->willReturnSelf();
        $qb->method('from')->willReturnSelf();
        $qb->method('innerJoin')->willReturnSelf();
        $qb->method('andWhere')->willReturnSelf();
        $qb->method('setParameter')->willReturnSelf();
        $qb->method('orderBy')->willReturnSelf();
        $qb->method('getQuery')->willReturn($query);

        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('createQueryBuilder')->willReturn($qb);
        $em->expects(self::once())->method('flush');

        $registry            = $this->createManagerRegistryWithQueryResult([]);
        $aggregateRepository = new CheckAggregateRepository($registry);

        $command = new RollupCommand($em, $aggregateRepository, ['periods' => []]);
        $tester  = new CommandTester($command);
        $tester->execute([]);

        self::assertSame(0, $tester->getStatusCode());
    }
}
