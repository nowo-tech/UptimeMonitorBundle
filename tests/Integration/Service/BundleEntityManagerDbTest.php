<?php

declare(strict_types=1);

namespace Nowo\UptimeMonitorBundle\Tests\Integration\Service;

use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\Decorator\EntityManagerDecorator;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Doctrine\ORM\UnitOfWork;
use Nowo\UptimeMonitorBundle\Check\CheckRunnerInterface;
use Nowo\UptimeMonitorBundle\Doctrine\UptimeEntityManager;
use Nowo\UptimeMonitorBundle\Doctrine\UptimeManagerRegistry;
use Nowo\UptimeMonitorBundle\Entity\Monitor;
use Nowo\UptimeMonitorBundle\Entity\Tenant;
use Nowo\UptimeMonitorBundle\Enum\CheckStatus;
use Nowo\UptimeMonitorBundle\Enum\MonitorType;
use Nowo\UptimeMonitorBundle\Event\MonitorWentDownEvent;
use Nowo\UptimeMonitorBundle\Model\CheckResultDto;
use Nowo\UptimeMonitorBundle\Repository\CheckAggregateRepository;
use Nowo\UptimeMonitorBundle\Repository\IncidentRepository;
use Nowo\UptimeMonitorBundle\Repository\MonitorRepository;
use Nowo\UptimeMonitorBundle\Repository\TenantRepository;
use Nowo\UptimeMonitorBundle\Service\AggregateService;
use Nowo\UptimeMonitorBundle\Service\CheckExecutorService;
use Nowo\UptimeMonitorBundle\Service\CheckLatencyNormalizer;
use Nowo\UptimeMonitorBundle\Service\MonitorBackupService;
use Nowo\UptimeMonitorBundle\Service\MonitorFactory;
use Nowo\UptimeMonitorBundle\Service\MonitorRetryService;
use Nowo\UptimeMonitorBundle\Service\NotificationService;
use Nowo\UptimeMonitorBundle\Service\StatusTransitionService;
use Nowo\UptimeMonitorBundle\Service\TenantProvisioner;
use Nowo\UptimeMonitorBundle\Tests\Fixtures\Host\Entity\HostNote;
use Nowo\UptimeMonitorBundle\Tests\Support\SqliteManagerRegistry;
use Nowo\UptimeMonitorBundle\Tests\Support\StubManagerRegistry;
use Nowo\UptimeMonitorBundle\Tests\Unit\Support\SyncDispatcherTestTrait;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\IgnoreDeprecations;
use PHPUnit\Framework\TestCase;
use Psr\EventDispatcher\EventDispatcherInterface as PsrEventDispatcherInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use RuntimeException;
use Symfony\Component\EventDispatcher\EventDispatcher;

use function dirname;

/**
 * REQ-ARCH-001 rule 6 (1.27.0): the bundle reads and writes through its private entity manager, on the connection of
 * the application manager that maps the bundle entities. A bundle write never flushes a change the application left
 * pending, a host transaction includes the bundle rows, a failed bundle flush closes only the bundle manager (the next
 * call gets a fresh one and the committed rows stay), and the incident listeners' changes in the application manager
 * are flushed only when nothing was pending there before them (`doctrine.flush_listener_changes`, BC, deprecated).
 */
#[Group('db')]
#[CoversClass(UptimeEntityManager::class)]
#[CoversClass(UptimeManagerRegistry::class)]
#[CoversClass(TenantProvisioner::class)]
#[CoversClass(CheckExecutorService::class)]
#[CoversClass(StatusTransitionService::class)]
#[CoversClass(MonitorBackupService::class)]
final class BundleEntityManagerDbTest extends TestCase
{
    use SyncDispatcherTestTrait;

    private SqliteManagerRegistry $doctrine;
    private EntityManagerInterface $application;
    private UptimeEntityManager $bundle;
    private UptimeManagerRegistry $registry;
    private EventDispatcher $dispatcher;

    protected function setUp(): void
    {
        $root           = dirname(__DIR__, 3);
        $this->doctrine = new SqliteManagerRegistry(['default' => [
            'Nowo\\UptimeMonitorBundle\\Entity'                        => $root . '/src/Entity',
            'Nowo\\UptimeMonitorBundle\\Tests\\Fixtures\\Host\\Entity' => $root . '/tests/Fixtures/Host/Entity',
        ]]);
        $application = $this->doctrine->getManager();
        self::assertInstanceOf(EntityManagerInterface::class, $application);
        $this->application = $application;
        (new SchemaTool($application))->createSchema($application->getMetadataFactory()->getAllMetadata());
        $this->bundle     = new UptimeEntityManager($this->doctrine);
        $this->registry   = new UptimeManagerRegistry($this->doctrine, $this->bundle);
        $this->dispatcher = new EventDispatcher();
    }

    public function testThePortsNeverFlushTheApplicationsPendingChanges(): void
    {
        $existing = new Tenant('existing', 'Existing');
        $this->application->persist($existing);
        $this->application->flush();
        // The application leaves an insert and an update pending in its own manager.
        $note = new HostNote('pending');
        $this->application->persist($note);
        $existing->setSettings(['owner' => 'application']);
        $provisioner = new TenantProvisioner(new TenantRepository($this->registry), $this->bundle);

        $tenant = $provisioner->ensure('acme', 'Acme');
        self::assertTrue($provisioner->setEnabled('existing', false));

        self::assertSame(0, $this->rows('host_note'), 'the application insert is still pending');
        self::assertSame('[]', $this->column("SELECT settings FROM uptime_tenant WHERE slug = 'existing'"), 'the application update is still pending');
        self::assertSame('0', (string) $this->column("SELECT enabled FROM uptime_tenant WHERE slug = 'existing'"), 'the bundle wrote its own change');
        self::assertSame(1, $this->rows("uptime_tenant WHERE slug = 'acme'"));
        self::assertTrue($this->application->getUnitOfWork()->isScheduledForInsert($note));
        self::assertTrue($this->application->contains($tenant), 'the port hands back the application manager\'s tenant');
        self::assertFalse($this->bundle->contains($tenant));

        // The application can relate its own work to it and flush its own manager.
        $this->application->flush();
        self::assertSame(1, $this->rows('host_note'));
        self::assertSame('{"owner":"application"}', $this->column("SELECT settings FROM uptime_tenant WHERE slug = 'existing'"));
        self::assertSame('0', (string) $this->column("SELECT enabled FROM uptime_tenant WHERE slug = 'existing'"), 'the bundle change survives the application flush');
    }

    public function testAHostTransactionIncludesTheBundleRows(): void
    {
        $provisioner = new TenantProvisioner(new TenantRepository($this->registry), $this->bundle);
        $connection  = $this->application->getConnection();

        $connection->beginTransaction();
        $provisioner->ensure('rolled-back', 'Rolled back');
        $connection->rollBack();
        self::assertSame(0, $this->rows("uptime_tenant WHERE slug = 'rolled-back'"));

        $this->bundle->reset();
        $connection->beginTransaction();
        $provisioner->ensure('committed', 'Committed');
        $connection->commit();
        self::assertSame(1, $this->rows("uptime_tenant WHERE slug = 'committed'"));
    }

    public function testAFailedBundleFlushClosesOnlyTheBundleManagerAndLosesNoCommittedRow(): void
    {
        $provisioner = new TenantProvisioner(new TenantRepository($this->registry), $this->bundle);
        $provisioner->ensure('first', 'First');
        $note = new HostNote('pending');
        $this->application->persist($note);

        $this->bundle->persist(new Tenant('first', 'Duplicate slug'));
        try {
            $this->bundle->flush();
            self::fail('the unique slug is enforced');
        } catch (UniqueConstraintViolationException) {
        }

        self::assertFalse($this->bundle->isOpen(), 'the failed flush closed the bundle manager');
        self::assertTrue($this->application->isOpen(), 'the application manager is untouched');
        self::assertTrue($this->application->getUnitOfWork()->isScheduledForInsert($note));

        // The next call works on a fresh bundle manager; the committed row is still there.
        $provisioner->ensure('second', 'Second');
        self::assertTrue($this->bundle->isOpen());
        self::assertSame(2, $this->rows('uptime_tenant'));
        self::assertSame(0, $this->rows('host_note'));
    }

    #[IgnoreDeprecations]
    public function testListenerChangesAreFlushedWhenNothingWasPendingBefore(): void
    {
        $this->dispatcher->addListener(MonitorWentDownEvent::class, function (): void {
            $this->application->persist(new HostNote('from the listener'));
        });
        $monitor = $this->monitor();

        $this->expectUserDeprecationMessageMatches('/left changes in an application entity manager/');
        $this->executor(true)->execute($monitor);

        self::assertSame(1, $this->rows('host_note'), 'BC: what the listener persisted is flushed');
    }

    #[IgnoreDeprecations]
    public function testListenerChangesStayPendingWithTheOptionOffAndAreReported(): void
    {
        $this->dispatcher->addListener(MonitorWentDownEvent::class, function (): void {
            $this->application->persist(new HostNote('from the listener'));
        });

        $this->expectUserDeprecationMessageMatches('/left changes in an application entity manager that the bundle does not flush \(doctrine\.flush_listener_changes is false\)/');
        $this->executor(false, $this->expectWarning(false))->execute($this->monitor());

        self::assertSame(0, $this->rows('host_note'));
        self::assertNotSame([], $this->application->getUnitOfWork()->getScheduledEntityInsertions());
    }

    #[IgnoreDeprecations]
    public function testAnApplicationThatAlreadyHadPendingChangesIsNeverFlushedAndIsReported(): void
    {
        $this->dispatcher->addListener(MonitorWentDownEvent::class, function (): void {
            $this->application->persist(new HostNote('from the listener'));
        });
        $monitor = $this->monitor();
        $this->application->persist(new HostNote('left pending by the application'));

        $this->expectUserDeprecationMessageMatches('/the manager already had pending changes of the application/');
        $this->executor(true, $this->expectWarning(true))->execute($monitor);

        self::assertSame(0, $this->rows('host_note'), 'a host-called check never flushes the application');
    }

    #[IgnoreDeprecations]
    public function testAnApplicationManagerThatCannotComputeItsChangesCountsAsPending(): void
    {
        $this->dispatcher->addListener(MonitorWentDownEvent::class, function (): void {
            $this->application->persist(new HostNote('from the listener'));
        });
        $monitor = $this->monitor();
        $this->withUnreadableApplicationChanges();

        $this->expectUserDeprecationMessageMatches('/the manager already had pending changes of the application/');
        $result = $this->executor(true, $this->expectWarning(true))->execute($monitor);
        self::assertSame(1, $this->rows('uptime_incident'));

        self::assertSame(CheckStatus::Down, $result->getStatus(), 'the check is stored');
        self::assertSame(0, $this->rows('host_note'));
    }

    #[IgnoreDeprecations]
    public function testAPlainPsrDispatcherIsAssumedToHaveListeners(): void
    {
        $this->dispatcher->addListener(MonitorWentDownEvent::class, function (): void {
            $this->application->persist(new HostNote('from the listener'));
        });

        $this->expectUserDeprecationMessageMatches('/the bundle flushes them for backward compatibility/');
        $this->executor(true, psrDispatcher: true)->execute($this->monitor());

        self::assertSame(1, $this->rows('host_note'));
    }

    public function testWithoutListenersTheApplicationManagerIsNeverInspected(): void
    {
        $monitor = $this->monitor();
        // Computing the change sets would throw: the bundle must not even try without listeners.
        $this->withUnreadableApplicationChanges();
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::never())->method('warning');

        $result = $this->executor(true, $logger)->execute($monitor);

        self::assertSame(CheckStatus::Down, $result->getStatus());
        self::assertSame(1, $this->rows('uptime_incident'));
    }

    public function testNothingToFlushWhenTheListenersLeftNoChange(): void
    {
        $seen = 0;
        $this->dispatcher->addListener(MonitorWentDownEvent::class, static function () use (&$seen): void {
            ++$seen;
        });

        $this->executor(true)->execute($this->monitor());

        self::assertSame(1, $seen);
        self::assertSame(0, $this->rows('host_note'));
    }

    public function testAClosedApplicationManagerIsSkipped(): void
    {
        $seen = 0;
        $this->dispatcher->addListener(MonitorWentDownEvent::class, static function () use (&$seen): void {
            ++$seen;
        });
        $monitor = $this->monitor();
        $this->application->close();

        $result = $this->executor(true)->execute($monitor);

        self::assertSame(CheckStatus::Down, $result->getStatus());
        self::assertSame(1, $seen);
        self::assertFalse($this->application->isOpen(), 'the bundle never reopens an application manager');
    }

    public function testWithoutADispatcherTheApplicationManagerIsNeverInspected(): void
    {
        $monitor = $this->monitor();
        $this->application->persist(new HostNote('pending'));

        $result = $this->executor(true, withDispatcher: false)->execute($monitor);

        self::assertSame(CheckStatus::Down, $result->getStatus());
        self::assertSame(1, $this->rows('uptime_incident'));
        self::assertSame(0, $this->rows('host_note'));
    }

    public function testAnApplicationManagerClosedByAListenerIsNotFlushed(): void
    {
        $this->dispatcher->addListener(MonitorWentDownEvent::class, function (): void {
            $this->application->persist(new HostNote('from the listener'));
            $this->application->close();
        });

        $this->executor(true)->execute($this->monitor());

        self::assertSame(0, $this->rows('host_note'));
    }

    #[IgnoreDeprecations]
    public function testAFailingApplicationFlushIsLogged(): void
    {
        $this->application->getConnection()->executeStatement("CREATE TRIGGER host_note_fail BEFORE INSERT ON host_note BEGIN SELECT RAISE(ABORT, 'refused'); END");
        $this->dispatcher->addListener(MonitorWentDownEvent::class, function (): void {
            $this->application->persist(new HostNote('refused'));
        });
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())->method('error')->with('Uptime incident listener changes could not be flushed: {error}', self::anything());
        $monitor = $this->monitor();

        $this->expectUserDeprecationMessageMatches('/left changes in an application entity manager/');
        $result = $this->executor(true, $logger)->execute($monitor);

        self::assertSame(CheckStatus::Down, $result->getStatus(), 'the check is stored');
        self::assertSame(1, $this->rows('uptime_incident'));
    }

    public function testAMonitorOfTheApplicationManagerIsCheckedAsTheBundleCopy(): void
    {
        $monitor = $this->monitor();
        self::assertTrue($this->application->contains($monitor));

        $result = $this->executor(false)->execute($monitor);

        self::assertTrue($this->bundle->contains($result->getMonitor()));
        self::assertNotSame($monitor, $result->getMonitor());
        self::assertSame($monitor->getId(), $result->getMonitor()->getId());
        self::assertSame(1, $this->rows('uptime_check_result'));
    }

    public function testABackupImportIntoATenantOfTheApplicationManagerWritesOnlyTheBundleRows(): void
    {
        $tenant = new Tenant('main', 'Main');
        $this->application->persist($tenant);
        $this->application->flush();
        $this->application->persist(new HostNote('pending'));
        $monitors = new MonitorRepository($this->registry);
        $service  = new MonitorBackupService($monitors, new MonitorFactory($monitors), $this->bundle, $this->registry);

        $result = $service->import($tenant, ['version' => 1, 'monitors' => [
            ['name' => 'API', 'type' => 'https', 'target' => 'https://api.example.test'],
        ]]);

        self::assertSame(1, $result['imported']);
        self::assertSame(1, $this->rows('uptime_monitor'));
        self::assertSame(0, $this->rows('host_note'));
    }

    private function monitor(CheckStatus $last = CheckStatus::Up): Monitor
    {
        $tenant = new Tenant('main', 'Main');
        $this->application->persist($tenant);
        $monitor = new Monitor($tenant, 'API', MonitorType::Https, 'https://api.test');
        $monitor->setRetries(0);
        $monitor->setLastKnownStatus($last);
        $this->application->persist($monitor);
        $this->application->flush();

        return $monitor;
    }

    private function executor(bool $flushListenerChanges, ?LoggerInterface $logger = null, CheckStatus $status = CheckStatus::Down, bool $withDispatcher = true, bool $psrDispatcher = false): CheckExecutorService
    {
        $runner = $this->createStub(CheckRunnerInterface::class);
        $runner->method('supports')->willReturn(true);
        $runner->method('run')->willReturn(new CheckResultDto($status, 5, null, $status === CheckStatus::Up ? null : 'timeout'));

        return new CheckExecutorService(
            [$runner],
            $this->bundle,
            new AggregateService($this->bundle, new CheckAggregateRepository($this->registry), ['periods' => []]),
            new StatusTransitionService(
                $this->bundle,
                new IncidentRepository($this->registry),
                new NotificationService([], ['enabled' => false]),
                [],
                $withDispatcher ? ($psrDispatcher ? $this->psrDispatcher() : $this->dispatcher) : null,
                logger: $logger ?? new NullLogger(),
                flushListenerChanges: $flushListenerChanges,
            ),
            $this->pollingSyncDispatcher(),
            new CheckLatencyNormalizer(['min_latency_ms' => 0]),
            new MonitorRetryService(),
        );
    }

    /**
     * The bundle now sees the application manager through a decorator whose unit of work cannot be read (computing the
     * change sets fails); the listeners still write through the real application manager.
     */
    private function withUnreadableApplicationChanges(): void
    {
        $unreadable = new class($this->application) extends EntityManagerDecorator {
            public function getUnitOfWork(): UnitOfWork
            {
                throw new RuntimeException('change sets unavailable');
            }
        };
        $doctrine       = new StubManagerRegistry(['default' => $unreadable], ['default' => $this->application->getConnection()], $unreadable);
        $this->bundle   = new UptimeEntityManager($doctrine);
        $this->registry = new UptimeManagerRegistry($doctrine, $this->bundle);
    }

    /** A plain PSR-14 dispatcher (no hasListeners()) over the test dispatcher. */
    private function psrDispatcher(): PsrEventDispatcherInterface
    {
        return new class($this->dispatcher) implements PsrEventDispatcherInterface {
            public function __construct(private readonly EventDispatcher $inner)
            {
            }

            public function dispatch(object $event): object
            {
                return $this->inner->dispatch($event);
            }
        };
    }

    private function expectWarning(bool $pendingBefore): LoggerInterface
    {
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())->method('warning')->with(
            self::stringContains('does not flush'),
            self::callback(static fn (array $context): bool => $context['event'] === MonitorWentDownEvent::class && $context['pending_before'] === $pendingBefore),
        );

        return $logger;
    }

    private function rows(string $from): int
    {
        return (int) $this->application->getConnection()->fetchOne('SELECT COUNT(*) FROM ' . $from);
    }

    private function column(string $sql): mixed
    {
        $value = $this->application->getConnection()->fetchOne($sql);
        if ($value === false) {
            throw new RuntimeException('no row');
        }

        return $value;
    }
}
