<?php

declare(strict_types=1);

namespace Nowo\UptimeMonitorBundle\Tests\Unit\Service;

use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use InvalidArgumentException;
use Nowo\UptimeMonitorBundle\Entity\Monitor;
use Nowo\UptimeMonitorBundle\Entity\Tenant;
use Nowo\UptimeMonitorBundle\Enum\CheckStatus;
use Nowo\UptimeMonitorBundle\Enum\MonitorType;
use Nowo\UptimeMonitorBundle\Repository\MonitorRepository;
use Nowo\UptimeMonitorBundle\Repository\TenantRepository;
use Nowo\UptimeMonitorBundle\Service\UptimeDataClearService;
use Nowo\UptimeMonitorBundle\Tests\Support\SqliteEntityManagerFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(UptimeDataClearService::class)]
final class UptimeDataClearServiceTest extends TestCase
{
    public function testClearDeletesInBatchesAndResetsMonitors(): void
    {
        $em                                    = SqliteEntityManagerFactory::create();
        [$tenantRepo, $monitorRepo, $monitors] = $this->fixtures($em);

        SqliteEntityManagerFactory::addCheckResults($em, $monitors['main'], 5, new DateTimeImmutable('-1 day'));
        SqliteEntityManagerFactory::addCheckResults($em, $monitors['other'], 2, new DateTimeImmutable('-1 day'));
        SqliteEntityManagerFactory::addAggregatesAndIncidents($em, $monitors['main'], 5);

        $service = new UptimeDataClearService($em, $tenantRepo, $monitorRepo, ['purge_batch_size' => 2]);
        $counts  = $service->clear();

        self::assertSame(['checks' => 7, 'aggregates' => 5, 'incidents' => 5, 'monitors_reset' => 2], $counts);
        self::assertNull($monitors['main']->getLastKnownStatus());
        self::assertNotNull($monitors['main']->getNextCheckAt());
        self::assertSame(0, SqliteEntityManagerFactory::count($em, 'uptime_check_result'));
        self::assertSame(0, SqliteEntityManagerFactory::count($em, 'uptime_check_aggregate'));
        self::assertSame(0, SqliteEntityManagerFactory::count($em, 'uptime_incident'));
    }

    public function testClearForTenantKeepsOtherTenantsAndHandlesEmptyTables(): void
    {
        $em                                    = SqliteEntityManagerFactory::create();
        [$tenantRepo, $monitorRepo, $monitors] = $this->fixtures($em);

        SqliteEntityManagerFactory::addCheckResults($em, $monitors['main'], 1500, new DateTimeImmutable('-1 day'));
        SqliteEntityManagerFactory::addCheckResults($em, $monitors['other'], 3, new DateTimeImmutable('-1 day'));

        // Default batch size (1000).
        $service = new UptimeDataClearService($em, $tenantRepo, $monitorRepo);
        $counts  = $service->clear('main');

        self::assertSame(['checks' => 1500, 'aggregates' => 0, 'incidents' => 0, 'monitors_reset' => 1], $counts);
        self::assertSame(3, SqliteEntityManagerFactory::count($em, 'uptime_check_result'));
        self::assertSame(CheckStatus::Up, $monitors['other']->getLastKnownStatus());
    }

    public function testClearThrowsWhenTenantMissing(): void
    {
        $tenantRepo = $this->createMock(TenantRepository::class);
        $tenantRepo->method('findOneBySlug')->with('missing')->willReturn(null);

        $service = new UptimeDataClearService(
            $this->createMock(EntityManagerInterface::class),
            $tenantRepo,
            $this->createMock(MonitorRepository::class),
        );

        $this->expectException(InvalidArgumentException::class);
        $service->clear('missing');
    }

    /**
     * @return array{TenantRepository, MonitorRepository, array<string, Monitor>}
     */
    private function fixtures(EntityManagerInterface $em): array
    {
        $tenants  = [];
        $monitors = [];
        foreach (['main', 'other'] as $slug) {
            $tenants[$slug]  = new Tenant($slug, ucfirst($slug));
            $monitors[$slug] = new Monitor($tenants[$slug], 'API ' . $slug, MonitorType::Https, 'https://' . $slug . '.example.test');
            $monitors[$slug]->setLastKnownStatus(CheckStatus::Up);
            $em->persist($tenants[$slug]);
            $em->persist($monitors[$slug]);
        }
        $em->flush();

        $tenantRepo = $this->createMock(TenantRepository::class);
        $tenantRepo->method('findOneBySlug')->willReturnCallback(static fn (string $slug): ?Tenant => $tenants[$slug] ?? null);

        $monitorRepo = $this->createMock(MonitorRepository::class);
        $monitorRepo->method('findAll')->willReturn(array_values($monitors));
        $monitorRepo->method('findByTenantSlug')->willReturnCallback(static fn (string $slug): array => [$monitors[$slug]]);

        return [$tenantRepo, $monitorRepo, $monitors];
    }
}
