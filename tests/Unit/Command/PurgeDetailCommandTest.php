<?php

declare(strict_types=1);

namespace Nowo\UptimeMonitorBundle\Tests\Unit\Command;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Query;
use Doctrine\ORM\QueryBuilder;
use Nowo\UptimeMonitorBundle\Command\PurgeDetailCommand;
use Nowo\UptimeMonitorBundle\Service\DetailRetentionService;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * @covers \Nowo\UptimeMonitorBundle\Command\PurgeDetailCommand
 */
final class PurgeDetailCommandTest extends TestCase
{
    public function testExecuteReportsPurgedCount(): void
    {
        $query = $this->createMock(Query::class);
        $query->method('execute')->willReturn(12);

        $qb = $this->createMock(QueryBuilder::class);
        $qb->method('delete')->willReturnSelf();
        $qb->method('andWhere')->willReturnSelf();
        $qb->method('setParameter')->willReturnSelf();
        $qb->method('getQuery')->willReturn($query);

        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('createQueryBuilder')->willReturn($qb);

        $command = new PurgeDetailCommand(new DetailRetentionService($em, ['purge_enabled' => true, 'detail_days' => 7]));
        $tester  = new CommandTester($command);
        $tester->execute([]);

        self::assertSame(0, $tester->getStatusCode());
        self::assertStringContainsString('12', $tester->getDisplay());
    }
}
