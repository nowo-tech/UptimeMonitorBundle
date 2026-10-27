<?php

declare(strict_types=1);

namespace Nowo\UptimeMonitorBundle\Tests\Integration\Command;

use DateTimeImmutable;
use Doctrine\DBAL\Configuration as DbalConfiguration;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use Doctrine\Persistence\ObjectManager;
use Nowo\UptimeMonitorBundle\Command\ClearDataCommand;
use Nowo\UptimeMonitorBundle\Command\RollupCommand;
use Nowo\UptimeMonitorBundle\Command\SeedDemoCommand;
use Nowo\UptimeMonitorBundle\Command\SyncSchemaCommand;
use Nowo\UptimeMonitorBundle\Entity\CheckAggregate;
use Nowo\UptimeMonitorBundle\Entity\CheckResult;
use Nowo\UptimeMonitorBundle\Entity\Monitor;
use Nowo\UptimeMonitorBundle\Entity\Tenant;
use Nowo\UptimeMonitorBundle\Enum\CheckStatus;
use Nowo\UptimeMonitorBundle\Enum\MonitorType;
use Nowo\UptimeMonitorBundle\Repository\CheckAggregateRepository;
use Nowo\UptimeMonitorBundle\Repository\CheckResultRepository;
use Nowo\UptimeMonitorBundle\Repository\MonitorRepository;
use Nowo\UptimeMonitorBundle\Repository\TenantRepository;
use Nowo\UptimeMonitorBundle\Service\DashboardSyncDispatcher;
use Nowo\UptimeMonitorBundle\Service\DemoSeedService;
use Nowo\UptimeMonitorBundle\Service\SummaryPayloadBuilder;
use Nowo\UptimeMonitorBundle\Service\TenantDashboardSerializer;
use Nowo\UptimeMonitorBundle\Service\UptimeDataClearService;
use Nowo\UptimeMonitorBundle\Service\UptimeMetricsService;
use Nowo\UptimeMonitorBundle\Tests\Support\FailingStatementMiddleware;
use Nowo\UptimeMonitorBundle\Tests\Support\SqliteEntityManagerFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Mercure\HubInterface;
use Symfony\Component\Mercure\Update;

#[Group('db')]
#[CoversClass(SyncSchemaCommand::class)]
#[CoversClass(SeedDemoCommand::class)]
#[CoversClass(ClearDataCommand::class)]
#[CoversClass(RollupCommand::class)]
#[CoversClass(DemoSeedService::class)]
#[CoversClass(UptimeDataClearService::class)]
final class CommandsDbTest extends TestCase
{
    /** @var list<string> */
    private array $refreshedTopics = [];

    /** SymfonyStyle wraps long lines: compare on collapsed whitespace. */
    private static function display(CommandTester $command): string
    {
        return (string) preg_replace('/\s+/', ' ', $command->getDisplay());
    }

    private function registry(EntityManagerInterface $em): ManagerRegistry
    {
        $registry = $this->createStub(ManagerRegistry::class);
        $registry->method('getManagerForClass')->willReturn($em);
        $registry->method('getManager')->willReturn($em);

        return $registry;
    }

    private function dispatcher(ManagerRegistry $registry): DashboardSyncDispatcher
    {
        $hub = $this->createStub(HubInterface::class);
        $hub->method('publish')->willReturnCallback(function (Update $update): string {
            $this->refreshedTopics[] = $update->getTopics()[0];

            return 'id';
        });

        return new DashboardSyncDispatcher(
            new SummaryPayloadBuilder(
                new MonitorRepository($registry),
                new CheckResultRepository($registry),
                new UptimeMetricsService(),
                new TenantDashboardSerializer(),
            ),
            ['sync' => 'mercure'],
            $hub,
        );
    }

    public function testSyncSchemaCreatesThenReportsUpToDate(): void
    {
        $em      = SqliteEntityManagerFactory::create(createSchema: false);
        $command = new CommandTester(new SyncSchemaCommand($this->registry($em), 'default'));

        self::assertSame(Command::SUCCESS, $command->execute([]));
        // On an empty database the create and the additive-update lists overlap (statement counts are
        // platform-specific): the duplicates are reported as skipped, not fatal.
        self::assertMatchesRegularExpression('/Executed [1-9]\d* SQL statement\(s\)\. Skipped [1-9]\d* already applied\./', self::display($command));

        self::assertSame(Command::SUCCESS, $command->execute(['--force' => true]));
        // SQLite: nothing left. MySQL / PostgreSQL: the foreign-key ALTERs are re-emitted and skipped as duplicates.
        self::assertMatchesRegularExpression('/already up to date|Executed 0 SQL statement\(s\)\. Skipped \d+ already applied\./', self::display($command));
    }

    public function testSyncSchemaRethrowsOtherErrors(): void
    {
        $em = SqliteEntityManagerFactory::create(
            (new DbalConfiguration())->setMiddlewares([new FailingStatementMiddleware(['CREATE TABLE uptime_tag ' => 'disk I/O error'])]),
            false,
        );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('disk I/O error');
        (new CommandTester(new SyncSchemaCommand($this->registry($em), 'default')))->execute([]);
    }

    public function testSyncSchemaRequiresAnOrmManager(): void
    {
        $registry = $this->createStub(ManagerRegistry::class);
        $registry->method('getManager')->willReturn($this->createStub(ObjectManager::class));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Connection "odm" is not an ORM entity manager.');
        (new CommandTester(new SyncSchemaCommand($registry, 'odm')))->execute([]);
    }

    public function testSeedDemoCreatesThenFreshReseeds(): void
    {
        $em       = SqliteEntityManagerFactory::create();
        $registry = $this->registry($em);
        $seed     = new DemoSeedService($em, new TenantRepository($registry), new MonitorRepository($registry), new MockClock('2026-01-01 00:00:00'));
        $command  = new CommandTester(new SeedDemoCommand($seed, $this->dispatcher($registry)));

        self::assertSame(0, $seed->freshSeed('empty', 'Empty')['monitors_removed']);

        $command->execute(['--tenant' => 'acme', '--name' => 'Acme']);
        self::assertStringContainsString('Tenant "acme" ready. Created 3 new monitor(s).', self::display($command));

        $command->execute(['--tenant' => 'acme', '--fresh' => true]);
        self::assertStringContainsString('Tenant "acme" reset. Removed 3 monitor(s), created 3', self::display($command));
        self::assertSame(['/uptime/acme', '/uptime/acme'], $this->refreshedTopics);

        $monitors = (new MonitorRepository($registry))->findByTenantSlug('acme');
        self::assertSame('2026-01-01 00:00:00', $monitors[0]->getNextCheckAt()?->format('Y-m-d H:i:s'));
    }

    public function testClearDataAbortsClearsAndReportsUnknownTenants(): void
    {
        $em       = SqliteEntityManagerFactory::create();
        $registry = $this->registry($em);
        $tenantA  = new Tenant('a', 'A');
        $tenantB  = new Tenant('b', 'B');
        $monitorA = new Monitor($tenantA, 'm', MonitorType::Http, 'https://a.test');
        $monitorB = new Monitor($tenantB, 'm', MonitorType::Http, 'https://b.test');
        foreach ([$tenantA, $tenantB, $monitorA, $monitorB] as $entity) {
            $em->persist($entity);
        }
        $em->flush();
        SqliteEntityManagerFactory::addCheckResults($em, $monitorA, 2, new DateTimeImmutable('-1 hour'));
        SqliteEntityManagerFactory::addCheckResults($em, $monitorB, 3, new DateTimeImmutable('-1 hour'));

        $clear   = new UptimeDataClearService($em, new TenantRepository($registry), new MonitorRepository($registry), [], new MockClock('2026-02-02 00:00:00'));
        $command = new CommandTester(new ClearDataCommand($clear, $this->dispatcher($registry)));

        $command->setInputs(['no']);
        self::assertSame(Command::SUCCESS, $command->execute([]));
        self::assertStringContainsString('Aborted.', self::display($command));
        self::assertSame(5, SqliteEntityManagerFactory::count($em, 'uptime_check_result'));

        self::assertSame(Command::FAILURE, $command->execute(['--tenant' => 'missing', '--no-interaction' => true]));
        self::assertStringContainsString('Tenant "missing" not found.', self::display($command));

        self::assertSame(Command::SUCCESS, $command->execute(['--tenant' => 'a', '--no-interaction' => true]));
        self::assertStringContainsString('Cleared 2 check(s), 0 aggregate(s), 0 incident(s). Reset 1 monitor(s).', self::display($command));
        self::assertSame(3, SqliteEntityManagerFactory::count($em, 'uptime_check_result'));

        self::assertSame(Command::SUCCESS, $command->execute(['--no-interaction' => true]));
        self::assertStringContainsString('Cleared 3 check(s)', self::display($command));
        self::assertSame(['/uptime/a', '/uptime/a', '/uptime/b'], $this->refreshedTopics);
        self::assertSame('2026-02-02 00:00:00', $monitorB->getNextCheckAt()?->format('Y-m-d H:i:s'));
        self::assertSame(['a', 'b'], $clear->listTenantSlugs());
    }

    public function testRollupCountsDownChecksAndFallsBackToDailyBuckets(): void
    {
        $em       = SqliteEntityManagerFactory::create();
        $registry = $this->registry($em);
        $tenant   = new Tenant('main', 'Main');
        $monitor  = new Monitor($tenant, 'API', MonitorType::Http, 'https://x.test');
        $em->persist($tenant);
        $em->persist($monitor);
        $em->persist(new CheckResult($monitor, CheckStatus::Up, 10, null, null, null, new DateTimeImmutable('2026-03-01 10:00:00')));
        $em->persist(new CheckResult($monitor, CheckStatus::Down, 30, null, null, null, new DateTimeImmutable('2026-03-01 11:00:00')));
        $em->flush();

        $command = new CommandTester(new RollupCommand($em, new CheckAggregateRepository($registry), ['periods' => ['bogus']], new MockClock('2026-03-02 00:00:00')));

        self::assertSame(Command::SUCCESS, $command->execute(['--days' => '5']));
        self::assertStringContainsString('Processed 2 check(s) into 1 aggregate bucket(s).', self::display($command));

        $aggregate = $em->getRepository(CheckAggregate::class)->findOneBy([]);
        self::assertInstanceOf(CheckAggregate::class, $aggregate);
        self::assertSame(2, $aggregate->getChecksTotal());
        self::assertSame(1, $aggregate->getChecksDown());
        self::assertSame(20, $aggregate->getLatencyAvgMs());
    }

    public function testRollupBuildsEveryConfiguredBucket(): void
    {
        $em       = SqliteEntityManagerFactory::create();
        $registry = $this->registry($em);
        $tenant   = new Tenant('main', 'Main');
        $monitor  = new Monitor($tenant, 'API', MonitorType::Http, 'https://x.test');
        $em->persist($tenant);
        $em->persist($monitor);
        $em->persist(new CheckResult($monitor, CheckStatus::Up, 10, null, null, null, new DateTimeImmutable('2026-03-15 10:20:00')));
        $em->flush();

        $command = new CommandTester(new RollupCommand($em, new CheckAggregateRepository($registry), ['periods' => ['hour', 'day', 'month', 'year']], new MockClock('2026-03-16 00:00:00')));
        $command->execute([]);

        $starts = array_map(
            static fn (CheckAggregate $a): string => $a->getPeriod()->value . '@' . $a->getPeriodStart()->format('Y-m-d H:i'),
            $em->getRepository(CheckAggregate::class)->findAll(),
        );
        sort($starts);
        self::assertSame(['day@2026-03-15 00:00', 'hour@2026-03-15 10:00', 'month@2026-03-01 00:00', 'year@2026-01-01 00:00'], $starts);
    }
}
