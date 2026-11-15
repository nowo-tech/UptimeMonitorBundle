<?php

declare(strict_types=1);

namespace Nowo\UptimeMonitorBundle\Tests\Integration\Service;

use DateTimeImmutable;
use Doctrine\DBAL\Driver\Exception as DriverException;
use Doctrine\DBAL\Exception\DeadlockException;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Events;
use Doctrine\Persistence\ManagerRegistry;
use Exception;
use Nowo\TransactionContract\TransientStorageFailure;
use Nowo\UptimeMonitorBundle\Check\CheckRunnerInterface;
use Nowo\UptimeMonitorBundle\Controller\PushController;
use Nowo\UptimeMonitorBundle\Doctrine\UptimeEntityManager;
use Nowo\UptimeMonitorBundle\Doctrine\UptimeManagerRegistry;
use Nowo\UptimeMonitorBundle\Entity\CheckAggregate;
use Nowo\UptimeMonitorBundle\Entity\Incident;
use Nowo\UptimeMonitorBundle\Entity\Monitor;
use Nowo\UptimeMonitorBundle\Entity\Tag;
use Nowo\UptimeMonitorBundle\Entity\Tenant;
use Nowo\UptimeMonitorBundle\Enum\CheckStatus;
use Nowo\UptimeMonitorBundle\Enum\MonitorType;
use Nowo\UptimeMonitorBundle\Event\MonitorWentDownEvent;
use Nowo\UptimeMonitorBundle\Model\CheckResultDto;
use Nowo\UptimeMonitorBundle\Notification\NotificationChannelInterface;
use Nowo\UptimeMonitorBundle\Notification\UptimeAlert;
use Nowo\UptimeMonitorBundle\Push\PushCredentials;
use Nowo\UptimeMonitorBundle\Repository\CheckAggregateRepository;
use Nowo\UptimeMonitorBundle\Repository\IncidentRepository;
use Nowo\UptimeMonitorBundle\Repository\MonitorRepository;
use Nowo\UptimeMonitorBundle\Repository\TenantRepository;
use Nowo\UptimeMonitorBundle\Service\AggregateService;
use Nowo\UptimeMonitorBundle\Service\CheckExecutorService;
use Nowo\UptimeMonitorBundle\Service\CheckLatencyNormalizer;
use Nowo\UptimeMonitorBundle\Service\DueChecksRunner;
use Nowo\UptimeMonitorBundle\Service\MonitorRetryService;
use Nowo\UptimeMonitorBundle\Service\NotificationService;
use Nowo\UptimeMonitorBundle\Service\StatusTransitionService;
use Nowo\UptimeMonitorBundle\Service\TenantProvisioner;
use Nowo\UptimeMonitorBundle\Tests\Support\SingleManagerRegistry;
use Nowo\UptimeMonitorBundle\Tests\Support\SqliteEntityManagerFactory;
use Nowo\UptimeMonitorBundle\Tests\Unit\Support\SyncDispatcherTestTrait;
use Nowo\UptimeMonitorBundle\Transaction\UptimeTransactionRunner;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\HttpFoundation\Request;

use function json_encode;

use const JSON_THROW_ON_ERROR;

/**
 * 1.29 (Beacon spec 239): the races of 1.28.3, replayed deterministically. A second entity manager on the same
 * connection plays the other request or worker: its writes are committed (autocommit) between the steps of the unit
 * under test, as a concurrent process would. Every test here fails on 1.28.3. SQLite by default; MySQL / PostgreSQL with
 * `UPTIME_TEST_DATABASE_URL` (the `database` CI job).
 */
#[Group('db')]
#[CoversClass(CheckExecutorService::class)]
#[CoversClass(DueChecksRunner::class)]
#[CoversClass(StatusTransitionService::class)]
#[CoversClass(TenantProvisioner::class)]
#[CoversClass(PushController::class)]
#[CoversClass(MonitorRepository::class)]
final class LoadReadinessDbTest extends TestCase
{
    use SyncDispatcherTestTrait;

    private const NOW = '2026-03-10 12:00:00';

    private EntityManagerInterface $em;
    private Tenant $tenant;
    private MockClock $clock;

    /** @var list<string> Alerts delivered, as "monitor:transition" */
    private array $alerts = [];

    /** @var (callable(Monitor): void)|null What the other process does while the check's network step runs */
    private $meanwhile;

    protected function setUp(): void
    {
        $this->em     = SqliteEntityManagerFactory::create();
        $this->clock  = new MockClock(self::NOW);
        $this->tenant = new Tenant('main', 'Main');
        $this->em->persist($this->tenant);
        $this->em->flush();
    }

    /**
     * R3: 1.28.3 flushed the monitor's whole configuration as loaded before the network step, so an edit saved while
     * the check ran (here the description) was written back to its old value.
     */
    public function testACheckKeepsAnEditSavedWhileItRan(): void
    {
        $monitor = $this->monitor('API');
        $monitor->setRetries(2);
        $this->em->flush();
        $this->meanwhile = function (Monitor $checked): void {
            $config                = $checked->getConfig();
            $config['description'] = 'edited meanwhile';
            $this->em->getConnection()->update('uptime_monitor', ['config' => json_encode($config, JSON_THROW_ON_ERROR)], ['id' => $checked->getId()]);
        };

        $this->executor($this->em)->execute($monitor);

        $this->em->clear();
        $stored = $this->em->find(Monitor::class, $monitor->getId());
        self::assertInstanceOf(Monitor::class, $stored);
        self::assertSame('edited meanwhile', $stored->getConfig()['description'] ?? null, 'the edit saved during the check is kept');
        self::assertSame(1, $stored->getConfig()['failure_streak'] ?? null, 'the check\'s own streak is stored too');
    }

    /**
     * R2: two schedulers that scanned the same due monitor. 1.28.3 checked it once per scheduler.
     */
    public function testTwoSchedulersThatScannedTheSameDueMonitorCheckItOnce(): void
    {
        $monitor = $this->monitor('API');
        $other   = $this->otherManager();
        // The other scheduler scanned before this one claimed: it holds the monitor as due.
        $scanned = (new MonitorRepository($this->registry($other)))->findDue($this->clock->now());
        self::assertCount(1, $scanned);
        $otherRunner     = new DueChecksRunner($this->scanOf($other, $scanned), $this->executor($other), true, $this->clock, 0);
        $checkedByOther  = null;
        $this->meanwhile = function () use ($otherRunner, &$checkedByOther): void {
            $this->meanwhile = null;
            $checkedByOther  = $otherRunner->runDueChecks();
        };

        $checked = (new DueChecksRunner(new MonitorRepository($this->registry($this->em)), $this->executor($this->em), true, $this->clock, 0))->runDueChecks();

        self::assertSame(1, $checked);
        self::assertSame(0, $checkedByOther, 'the monitor was claimed: the other scheduler skips it');
        self::assertSame(1, SqliteEntityManagerFactory::count($this->em, 'uptime_check_result'));
        self::assertNotNull($monitor->getId());
    }

    /**
     * R4: two checks of one monitor at the same time (a group refreshed by two children, a check run by hand). 1.28.3
     * judged the second on the status it loaded before the first one committed: a second "down" alert went out.
     */
    public function testConcurrentChecksOfOneMonitorJudgeTheCommittedStatus(): void
    {
        $monitor = $this->monitor('API');
        $monitor->setLastKnownStatus(CheckStatus::Up);
        $this->em->flush();
        $other           = $this->otherManager();
        $otherExecutor   = $this->executor($other);
        $this->meanwhile = function (Monitor $checked) use ($other, $otherExecutor): void {
            $this->meanwhile = null;
            $otherExecutor->execute($other->find(Monitor::class, $checked->getId()) ?? $checked);
        };

        $this->executor($this->em)->execute($monitor);

        self::assertSame(['API:down'], $this->alerts, 'one alert for one transition');
        self::assertSame(2, SqliteEntityManagerFactory::count($this->em, 'uptime_check_result'));
        self::assertSame(1, SqliteEntityManagerFactory::count($this->em, 'uptime_incident'));
        $this->em->clear();
        $bucket = $this->em->getRepository(CheckAggregate::class)->findOneBy([]);
        self::assertInstanceOf(CheckAggregate::class, $bucket);
        self::assertSame(2, $bucket->getChecksTotal(), 'both checks are counted');
    }

    /**
     * A transient failure inside the check's unit of work is replayed: one result, one incident, one alert and one
     * event (1.28.3 sent the alert before its flush, and a deadlock lost the check).
     */
    public function testATransientFailureOfTheCheckIsReplayedWithoutDuplicateSideEffects(): void
    {
        $monitor = $this->monitor('API');
        $monitor->setLastKnownStatus(CheckStatus::Up);
        $this->em->flush();
        $events     = 0;
        $dispatcher = new EventDispatcher();
        $dispatcher->addListener(MonitorWentDownEvent::class, static function () use (&$events): void {
            ++$events;
        });
        $failures = 1;
        $registry = $this->registry($this->em);
        $flaky    = new class($registry, $failures) extends IncidentRepository {
            public function __construct(ManagerRegistry $registry, private int &$failures)
            {
                parent::__construct($registry);
            }

            public function findOpenForMonitor(Monitor $monitor): ?Incident
            {
                if ($this->failures-- > 0) {
                    throw new TransientStorageFailure(0);
                }

                return parent::findOpenForMonitor($monitor);
            }
        };

        $this->executor($this->em, $flaky, $dispatcher)->execute($monitor);

        self::assertSame(-1, $failures, 'the unit ran twice');
        self::assertSame(['API:down'], $this->alerts);
        self::assertSame(1, $events);
        self::assertSame(1, SqliteEntityManagerFactory::count($this->em, 'uptime_check_result'));
        self::assertSame(1, SqliteEntityManagerFactory::count($this->em, 'uptime_incident'));
    }

    /**
     * Audit QK-L1: what an incident listener left in the bundle's manager fails to flush after the commit. The check is
     * stored and its transition is never judged again, so its alert must still go out (on 9efa776 the failure escaped
     * and the "down" alert was lost for good).
     */
    public function testAFailingListenerFlushNeverSuppressesTheAlert(): void
    {
        $monitor = $this->monitor('API');
        $monitor->setLastKnownStatus(CheckStatus::Up);
        $this->em->flush();
        $dispatcher = new EventDispatcher();
        $dispatcher->addListener(MonitorWentDownEvent::class, function (): void {
            // Two tags with one name: the unique key refuses the flush after the commit.
            $this->em->persist(new Tag($this->tenant, 'dup'));
            $this->em->persist(new Tag($this->tenant, 'dup'));
        });

        $result = $this->executor($this->em, null, $dispatcher)->execute($monitor);

        self::assertSame(CheckStatus::Down, $result->getStatus());
        self::assertSame(['API:down'], $this->alerts);
        self::assertSame(1, SqliteEntityManagerFactory::count($this->em, 'uptime_check_result'));
    }

    /**
     * Audit QK-L2: the time of a sent alert is stored on the row locked again by id, also when its unit is replayed
     * after a storage failure (on 9efa776 the replay wrote to the entity of the replaced manager: nothing stored), and
     * a later time stored meanwhile is never moved back.
     */
    public function testTheTimeOfASentAlertSurvivesAReplayAndNeverMovesBack(): void
    {
        $monitor = $this->monitor('API');
        $later   = $this->monitor('Web');
        $future  = new DateTimeImmutable('2026-03-10 13:00:00');
        $this->em->getConnection()->update('uptime_monitor', ['last_alert_at' => $future], ['id' => $later->getId()], ['last_alert_at' => Types::DATETIME_IMMUTABLE]);
        $private = new UptimeEntityManager(new SingleManagerRegistry($this->em));
        $mine    = $private->find(Monitor::class, $monitor->getId());
        $theirs  = $private->find(Monitor::class, $later->getId());
        self::assertInstanceOf(Monitor::class, $mine);
        self::assertInstanceOf(Monitor::class, $theirs);
        $deadlocks = 1;
        $this->em->getEventManager()->addEventListener([Events::onFlush], new class($deadlocks) {
            public function __construct(private int &$deadlocks)
            {
            }

            public function onFlush(): void
            {
                if ($this->deadlocks-- > 0) {
                    throw new DeadlockException(new class('deadlock', 1213) extends Exception implements DriverException {
                        public function getSQLState(): string
                        {
                            return '40001';
                        }
                    }, null);
                }
            }
        });
        $transitions = new StatusTransitionService(
            $private,
            new IncidentRepository($this->registry($private)),
            new NotificationService([$this->recordingChannel()], ['enabled' => true]),
            ['enabled' => true],
            clock: $this->clock,
            transactionRunner: new UptimeTransactionRunner($private, new UptimeManagerRegistry($this->createStub(ManagerRegistry::class), $private), UptimeEntityManager::NAME, 1, 0),
        );

        $transitions->sendCommitted([new UptimeAlert($mine, UptimeAlert::TRANSITION_DOWN, CheckStatus::Down, CheckStatus::Up), new UptimeAlert($theirs, UptimeAlert::TRANSITION_DOWN, CheckStatus::Down, CheckStatus::Up)]);

        self::assertSame(-2, $deadlocks, 'three flushes: the first unit (deadlock), its replay, the second alert\'s unit');
        $connection = $this->em->getConnection();
        self::assertSame(self::NOW, (string) $connection->fetchOne('SELECT last_alert_at FROM uptime_monitor WHERE id = ?', [$monitor->getId()]));
        self::assertSame('2026-03-10 13:00:00', (string) $connection->fetchOne('SELECT last_alert_at FROM uptime_monitor WHERE id = ?', [$later->getId()]));
    }

    /**
     * R5: two first visits of a project provision its tenant at the same time. 1.28.3 failed the second one with a
     * unique violation (a 500 and a closed entity manager).
     */
    public function testTwoProvisioningsOfOneNewTenantBothGetIt(): void
    {
        $registry = $this->registry($this->em);
        $tenants  = new class($registry, $this->em) extends TenantRepository {
            private bool $first = true;

            public function __construct(ManagerRegistry $registry, private readonly EntityManagerInterface $em)
            {
                parent::__construct($registry);
            }

            public function findOneBySlug(string $slug): ?Tenant
            {
                if ($this->first) {
                    // The other request inserts the tenant right after this one found none.
                    $this->first = false;
                    // Through its own entity manager: ids from a sequence (PostgreSQL with DBAL 3) are asked first.
                    $theirs = new EntityManager($this->em->getConnection(), $this->em->getConfiguration());
                    $theirs->persist(new Tenant($slug, 'Theirs'));
                    $theirs->flush();

                    return null;
                }

                return parent::findOneBySlug($slug);
            }
        };

        $tenant = (new TenantProvisioner($tenants, $this->em))->ensure('acme', 'Ours');

        self::assertSame('acme', $tenant->getSlug());
        self::assertSame('Theirs', $tenant->getName(), 'the row the other request wrote');
        self::assertSame(1, (int) $this->em->getConnection()->fetchOne("SELECT COUNT(*) FROM uptime_tenant WHERE slug = 'acme'"));
    }

    /**
     * R6: two pushes of one monitor at the same time. 1.28.3 judged the rate on the state it loaded, so both were
     * accepted; the push stored meanwhile now makes the second one wait.
     */
    public function testAPushStoredMeanwhileMakesTheNextOneWait(): void
    {
        $monitor = $this->monitor('Agent', MonitorType::Push);
        $monitor->setPushKey('0123456789abcdef0123456789abcdef');
        $credentials = new PushCredentials('kernel-secret');
        $token       = $credentials->issueToken($monitor);
        $this->em->flush();
        $registry = $this->registry($this->em);
        $now      = $this->clock->now();
        $monitors = new class($registry, $this->em, $now) extends MonitorRepository {
            public function __construct(ManagerRegistry $registry, private readonly EntityManagerInterface $em, private readonly DateTimeImmutable $now)
            {
                parent::__construct($registry);
            }

            public function findOneByPushKey(string $key): ?Monitor
            {
                $monitor = parent::findOneByPushKey($key);
                // The other push is accepted and committed right after this one read the monitor.
                $this->em->getConnection()->update('uptime_monitor', ['push_last_at' => $this->now], ['push_key' => $key], ['push_last_at' => Types::DATETIME_IMMUTABLE]);

                return $monitor;
            }
        };
        $controller = new PushController($monitors, $credentials, $this->em, [
            'enabled'                  => true,
            'max_body_bytes'           => 8192,
            'signature_window_seconds' => 300,
            'min_push_seconds'         => 10,
        ], $this->clock);
        $request = Request::create('/push/' . $monitor->getPushKey(), 'POST', server: ['CONTENT_TYPE' => 'application/json', 'HTTP_AUTHORIZATION' => 'Bearer ' . $token], content: '{"cpu_pct":1}');

        $response = $controller((string) $monitor->getPushKey(), $request);

        self::assertSame(429, $response->getStatusCode());
        self::assertSame('30', $response->headers->get('Retry-After'));
        self::assertNull($monitor->getPushSample(), 'nothing stored');
    }

    private function monitor(string $name, MonitorType $type = MonitorType::Https): Monitor
    {
        $monitor = new Monitor($this->tenant, $name, $type, $type === MonitorType::Push ? 'push' : 'https://api.test');
        $monitor->setRetries(0);
        $monitor->setNextCheckAt($this->clock->now()->modify('-1 minute'));
        $this->em->persist($monitor);
        $this->em->flush();

        return $monitor;
    }

    /**
     * Another process: its own entity manager (identity map) on the same connection.
     */
    private function otherManager(): EntityManagerInterface
    {
        return new EntityManager($this->em->getConnection(), $this->em->getConfiguration());
    }

    private function registry(EntityManagerInterface $em): ManagerRegistry
    {
        $registry = $this->createStub(ManagerRegistry::class);
        $registry->method('getManagerForClass')->willReturn($em);

        return $registry;
    }

    /**
     * The other scheduler's repository: its scan already ran (the given due list), its claims are real.
     *
     * @param list<Monitor> $scanned
     */
    private function scanOf(EntityManagerInterface $em, array $scanned): MonitorRepository
    {
        return new class($this->registry($em), $scanned) extends MonitorRepository {
            /**
             * @param list<Monitor> $scanned
             */
            public function __construct(ManagerRegistry $registry, private readonly array $scanned)
            {
                parent::__construct($registry);
            }

            public function findDue(DateTimeImmutable $now): array
            {
                return $this->scanned;
            }
        };
    }

    private function recordingChannel(): NotificationChannelInterface
    {
        return new class($this->alerts) implements NotificationChannelInterface {
            /**
             * @param list<string> $alerts
             */
            public function __construct(
                // @phpstan-ignore property.onlyWritten (a reference to the test's list)
                private array &$alerts,
            ) {
            }

            public function send(UptimeAlert $alert): bool
            {
                $this->alerts[] = $alert->getMonitor()->getName() . ':' . $alert->getTransition();

                return true;
            }

            public function isEnabled(): bool
            {
                return true;
            }

            public function getName(): string
            {
                return 'recording';
            }
        };
    }

    private function executor(EntityManagerInterface $em, ?IncidentRepository $incidents = null, ?EventDispatcher $dispatcher = null): CheckExecutorService
    {
        $registry = $this->registry($em);
        $runner   = $this->createStub(CheckRunnerInterface::class);
        $runner->method('supports')->willReturn(true);
        $runner->method('run')->willReturnCallback(function (Monitor $monitor): CheckResultDto {
            // The network step: the other process works meanwhile.
            if ($this->meanwhile !== null) {
                ($this->meanwhile)($monitor);
            }

            return new CheckResultDto(CheckStatus::Down, 5, null, 'timeout');
        });
        $channel      = $this->recordingChannel();
        $transactions = new UptimeTransactionRunner($em, backoffBaseMilliseconds: 0);

        return new CheckExecutorService(
            [$runner],
            $em,
            new AggregateService($em, new CheckAggregateRepository($registry), ['periods' => ['day']], $this->clock),
            new StatusTransitionService($em, $incidents ?? new IncidentRepository($registry), new NotificationService([$channel], ['enabled' => true]), ['enabled' => true, 'cooldown_seconds' => 0], $dispatcher, $this->clock, transactionRunner: $transactions),
            $this->pollingSyncDispatcher(),
            new CheckLatencyNormalizer(['min_latency_ms' => 0]),
            new MonitorRetryService(),
            $this->clock,
            $transactions,
        );
    }
}
