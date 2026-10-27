<?php

declare(strict_types=1);

namespace Nowo\UptimeMonitorBundle\Tests\Unit\Command;

use DateTimeImmutable;
use Nowo\UptimeMonitorBundle\Command\PurgeDetailCommand;
use Nowo\UptimeMonitorBundle\Entity\Monitor;
use Nowo\UptimeMonitorBundle\Entity\Tenant;
use Nowo\UptimeMonitorBundle\Enum\MonitorType;
use Nowo\UptimeMonitorBundle\Repository\TenantRepository;
use Nowo\UptimeMonitorBundle\Service\DetailRetentionService;
use Nowo\UptimeMonitorBundle\Tests\Support\SqliteEntityManagerFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;

#[CoversClass(PurgeDetailCommand::class)]
final class PurgeDetailCommandTest extends TestCase
{
    public function testExecuteReportsPurgedCount(): void
    {
        $em      = SqliteEntityManagerFactory::create();
        $tenant  = new Tenant('main', 'Main');
        $monitor = new Monitor($tenant, 'API', MonitorType::Https, 'https://example.test');
        $em->persist($tenant);
        $em->persist($monitor);
        $em->flush();
        SqliteEntityManagerFactory::addCheckResults($em, $monitor, 12, new DateTimeImmutable('-30 days'));
        SqliteEntityManagerFactory::addCheckResults($em, $monitor, 4, new DateTimeImmutable('-1 day'));

        $tenantRepo = $this->createMock(TenantRepository::class);
        $tenantRepo->method('findAll')->willReturn([$tenant]);
        $tenantRepo->method('findOneBySlug')->willReturn($tenant);

        $command = new PurgeDetailCommand(new DetailRetentionService(
            $em,
            $tenantRepo,
            ['purge_enabled' => true, 'detail_days' => 7, 'purge_batch_size' => 5],
        ));
        $tester = new CommandTester($command);
        $tester->execute([]);

        self::assertSame(0, $tester->getStatusCode());
        self::assertStringContainsString('Purged 12 detail record(s).', $tester->getDisplay());
    }
}
