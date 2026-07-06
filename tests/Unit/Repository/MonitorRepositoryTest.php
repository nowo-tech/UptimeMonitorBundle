<?php

declare(strict_types=1);

namespace Nowo\UptimeMonitorBundle\Tests\Unit\Repository;

use DateTimeImmutable;
use Nowo\UptimeMonitorBundle\Entity\Monitor;
use Nowo\UptimeMonitorBundle\Entity\Tenant;
use Nowo\UptimeMonitorBundle\Enum\MonitorType;
use Nowo\UptimeMonitorBundle\Repository\MonitorRepository;
use Nowo\UptimeMonitorBundle\Tests\Unit\Support\DoctrineQueryBuilderTrait;
use PHPUnit\Framework\TestCase;

/**
 * @covers \Nowo\UptimeMonitorBundle\Repository\MonitorRepository
 */
final class MonitorRepositoryTest extends TestCase
{
    use DoctrineQueryBuilderTrait;

    public function testFindByTenantSlug(): void
    {
        $monitor    = new Monitor(new Tenant('main', 'Main'), 'API', MonitorType::Https, 'https://x.test');
        $registry   = $this->createManagerRegistryWithQueryResult([$monitor]);
        $repository = new MonitorRepository($registry);

        self::assertSame([$monitor], $repository->findByTenantSlug('main'));
    }

    public function testFindDue(): void
    {
        $monitor    = new Monitor(new Tenant('main', 'Main'), 'API', MonitorType::Https, 'https://x.test');
        $registry   = $this->createManagerRegistryWithQueryResult([$monitor]);
        $repository = new MonitorRepository($registry);

        self::assertSame([$monitor], $repository->findDue(new DateTimeImmutable()));
    }
}
