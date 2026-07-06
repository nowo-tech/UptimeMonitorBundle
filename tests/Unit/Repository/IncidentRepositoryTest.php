<?php

declare(strict_types=1);

namespace Nowo\UptimeMonitorBundle\Tests\Unit\Repository;

use Nowo\UptimeMonitorBundle\Entity\Incident;
use Nowo\UptimeMonitorBundle\Entity\Monitor;
use Nowo\UptimeMonitorBundle\Entity\Tenant;
use Nowo\UptimeMonitorBundle\Enum\CheckStatus;
use Nowo\UptimeMonitorBundle\Enum\MonitorType;
use Nowo\UptimeMonitorBundle\Repository\IncidentRepository;
use Nowo\UptimeMonitorBundle\Tests\Unit\Support\DoctrineQueryBuilderTrait;
use PHPUnit\Framework\TestCase;

/**
 * @covers \Nowo\UptimeMonitorBundle\Repository\IncidentRepository
 */
final class IncidentRepositoryTest extends TestCase
{
    use DoctrineQueryBuilderTrait;

    public function testFindOpenForMonitor(): void
    {
        $monitor  = new Monitor(new Tenant('main', 'Main'), 'API', MonitorType::Https, 'https://x.test');
        $incident = new Incident($monitor, CheckStatus::Down);

        $registry   = $this->createManagerRegistryWithQueryResult([$incident], Incident::class);
        $repository = new IncidentRepository($registry);

        self::assertSame($incident, $repository->findOpenForMonitor($monitor));
    }
}
