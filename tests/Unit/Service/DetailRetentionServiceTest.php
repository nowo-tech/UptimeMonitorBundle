<?php

declare(strict_types=1);

namespace Nowo\UptimeMonitorBundle\Tests\Unit\Service;

use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Nowo\UptimeMonitorBundle\Entity\Monitor;
use Nowo\UptimeMonitorBundle\Entity\Tenant;
use Nowo\UptimeMonitorBundle\Enum\MonitorType;
use Nowo\UptimeMonitorBundle\Repository\TenantRepository;
use Nowo\UptimeMonitorBundle\Service\DetailRetentionService;
use Nowo\UptimeMonitorBundle\Tests\Support\SqliteEntityManagerFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

#[CoversClass(DetailRetentionService::class)]
final class DetailRetentionServiceTest extends TestCase
{
    public function testPurgeReturnsZeroWhenDisabled(): void
    {
        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects(self::never())->method('createQueryBuilder');

        $tenantRepo = $this->createMock(TenantRepository::class);
        $tenantRepo->method('findAll')->willReturn([new Tenant('main', 'Main')]);

        $service = new DetailRetentionService($em, $tenantRepo, ['purge_enabled' => false, 'detail_days' => 30]);

        self::assertSame(0, $service->purgeExpiredDetail());
        self::assertSame(0, $service->purgeExpiredDetailForTenant('main'));
    }

    public function testPurgeReturnsZeroWhenDetailDaysInvalid(): void
    {
        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects(self::never())->method('createQueryBuilder');

        $tenantRepo = $this->createMock(TenantRepository::class);
        $tenantRepo->method('findAll')->willReturn([new Tenant('main', 'Main')]);

        $service = new DetailRetentionService($em, $tenantRepo, ['purge_enabled' => true, 'detail_days' => null]);

        self::assertSame(0, $service->purgeExpiredDetail());
    }

    public function testPurgeOnEmptyTableReturnsZero(): void
    {
        $em           = SqliteEntityManagerFactory::create();
        [$tenantRepo] = $this->tenants($em, ['main']);

        $service = new DetailRetentionService($em, $tenantRepo, ['purge_enabled' => true, 'detail_days' => 7]);

        self::assertSame(0, $service->purgeExpiredDetail());
    }

    public function testPurgeDeletesExpiredRowsInSeveralBatchesAndKeepsRecentOnes(): void
    {
        $em                      = SqliteEntityManagerFactory::create();
        [$tenantRepo, $monitors] = $this->tenants($em, ['main', 'other']);

        SqliteEntityManagerFactory::addCheckResults($em, $monitors['main'], 2500, new DateTimeImmutable('-30 days'));
        SqliteEntityManagerFactory::addCheckResults($em, $monitors['main'], 40, new DateTimeImmutable('-1 day'));
        SqliteEntityManagerFactory::addCheckResults($em, $monitors['other'], 300, new DateTimeImmutable('-30 days'));

        // Default batch size (1000): 2500 expired rows take three batches.
        $service = new DetailRetentionService($em, $tenantRepo, ['purge_enabled' => true, 'detail_days' => 7]);

        self::assertSame(2500, $service->purgeExpiredDetailForTenant('main'));
        self::assertSame(340, SqliteEntityManagerFactory::count($em, 'uptime_check_result'));

        self::assertSame(300, $service->purgeExpiredDetail());
        self::assertSame(40, SqliteEntityManagerFactory::count($em, 'uptime_check_result'));
    }

    public function testPurgeHonoursConfiguredBatchSizeAndTenantOverride(): void
    {
        $em                      = SqliteEntityManagerFactory::create();
        [$tenantRepo, $monitors] = $this->tenants($em, ['main']);

        SqliteEntityManagerFactory::addCheckResults($em, $monitors['main'], 7, new DateTimeImmutable('-20 days'));
        SqliteEntityManagerFactory::addCheckResults($em, $monitors['main'], 5, new DateTimeImmutable('-3 days'));

        $monitors['main']->getTenant()->setSettings(['detail_days' => 2]);
        $em->flush();

        $service = new DetailRetentionService($em, $tenantRepo, [
            'purge_enabled'    => true,
            'detail_days'      => 30,
            'purge_batch_size' => 3,
        ]);

        self::assertSame(12, $service->purgeExpiredDetail());
        self::assertSame(0, SqliteEntityManagerFactory::count($em, 'uptime_check_result'));
    }

    /**
     * @param list<string> $slugs
     *
     * @return array{TenantRepository, array<string, Monitor>}
     */
    private function tenants(EntityManagerInterface $em, array $slugs): array
    {
        $tenants  = [];
        $monitors = [];
        foreach ($slugs as $slug) {
            $tenants[$slug]  = new Tenant($slug, ucfirst($slug));
            $monitors[$slug] = new Monitor($tenants[$slug], 'API ' . $slug, MonitorType::Https, 'https://' . $slug . '.example.test');
            $em->persist($tenants[$slug]);
            $em->persist($monitors[$slug]);
        }
        $em->flush();

        $tenantRepo = $this->createMock(TenantRepository::class);
        $tenantRepo->method('findAll')->willReturn(array_values($tenants));
        $tenantRepo->method('findOneBySlug')->willReturnCallback(static fn (string $slug): ?Tenant => $tenants[$slug] ?? null);

        return [$tenantRepo, $monitors];
    }

    public function testPurgeLogsStartAndDeletedRows(): void
    {
        $em                      = SqliteEntityManagerFactory::create();
        [$tenantRepo, $monitors] = $this->tenants($em, ['main']);
        SqliteEntityManagerFactory::addCheckResults($em, $monitors['main'], 3, new DateTimeImmutable('-30 days'));

        $messages = [];
        $logger   = $this->createMock(LoggerInterface::class);
        $logger->expects(self::exactly(2))->method('info')->willReturnCallback(static function (string $message, array $context) use (&$messages): void {
            $messages[] = $context;
        });

        $service = new DetailRetentionService($em, $tenantRepo, ['purge_enabled' => true, 'detail_days' => 7], logger: $logger);

        self::assertSame(3, $service->purgeExpiredDetailForTenant('main'));
        self::assertSame(['bundle' => 'NowoUptimeMonitorBundle', 'tenant' => 'main', 'days' => 7], $messages[0]);
        self::assertSame(3, $messages[1]['deleted']);
    }

    public function testPurgeFailureIsLoggedWithTheExceptionClassAndRethrown(): void
    {
        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('createQueryBuilder')->willThrowException(new RuntimeException('SQLSTATE secret'));
        $tenantRepo = $this->createStub(TenantRepository::class);

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())->method('error')->with(
            self::anything(),
            self::callback(static fn (array $context): bool => $context['error'] === RuntimeException::class),
        );

        $this->expectException(RuntimeException::class);

        (new DetailRetentionService($em, $tenantRepo, ['purge_enabled' => true, 'detail_days' => 7], logger: $logger))->purgeExpiredDetailForTenant('main');
    }
}
