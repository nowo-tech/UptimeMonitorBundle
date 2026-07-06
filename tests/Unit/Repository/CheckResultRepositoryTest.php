<?php

declare(strict_types=1);

namespace Nowo\UptimeMonitorBundle\Tests\Unit\Repository;

use Nowo\UptimeMonitorBundle\Entity\CheckResult;
use Nowo\UptimeMonitorBundle\Entity\Monitor;
use Nowo\UptimeMonitorBundle\Entity\Tenant;
use Nowo\UptimeMonitorBundle\Enum\CheckStatus;
use Nowo\UptimeMonitorBundle\Enum\MonitorType;
use Nowo\UptimeMonitorBundle\Repository\CheckResultRepository;
use Nowo\UptimeMonitorBundle\Tests\Unit\Support\DoctrineQueryBuilderTrait;
use Nowo\UptimeMonitorBundle\Tests\Unit\Support\EntityIdTrait;
use PHPUnit\Framework\TestCase;

/**
 * @covers \Nowo\UptimeMonitorBundle\Repository\CheckResultRepository
 */
final class CheckResultRepositoryTest extends TestCase
{
    use DoctrineQueryBuilderTrait;
    use EntityIdTrait;

    public function testFindLatestByMonitorIdsReturnsEmptyForEmptyInput(): void
    {
        $registry   = $this->createManagerRegistryWithQueryResult([], CheckResult::class);
        $repository = new CheckResultRepository($registry);

        self::assertSame([], $repository->findLatestByMonitorIds([]));
    }

    public function testFindLatestByMonitorIdsKeepsFirstPerMonitor(): void
    {
        $monitor = new Monitor(new Tenant('main', 'Main'), 'API', MonitorType::Https, 'https://x.test');
        $this->setEntityId($monitor, 7);

        $latest = new CheckResult($monitor, CheckStatus::Up, 10);
        $older  = new CheckResult($monitor, CheckStatus::Down, 20);

        $registry   = $this->createManagerRegistryWithQueryResult([$latest, $older], CheckResult::class);
        $repository = new CheckResultRepository($registry);

        self::assertSame([7 => $latest], $repository->findLatestByMonitorIds([7]));
    }

    public function testFindLatestForMonitor(): void
    {
        $monitor = new Monitor(new Tenant('main', 'Main'), 'API', MonitorType::Https, 'https://x.test');
        $result  = new CheckResult($monitor, CheckStatus::Up, 5);

        $registry   = $this->createManagerRegistryWithQueryResult([$result], CheckResult::class);
        $repository = new CheckResultRepository($registry);

        self::assertSame($result, $repository->findLatestForMonitor($monitor));
    }
}
