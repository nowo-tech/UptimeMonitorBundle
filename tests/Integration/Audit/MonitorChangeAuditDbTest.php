<?php

declare(strict_types=1);

namespace Nowo\UptimeMonitorBundle\Tests\Integration\Audit;

use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Id\AbstractIdGenerator;
use Doctrine\Persistence\ManagerRegistry;
use Nowo\TransactionContract\TransientStorageFailure;
use Nowo\TransactionDoctrine\WriteUnit\CommitLedger;
use Nowo\UptimeMonitorBundle\Audit\MonitorAuditState;
use Nowo\UptimeMonitorBundle\Audit\MonitorChangeActorResolverInterface;
use Nowo\UptimeMonitorBundle\Audit\MonitorChangeCapture;
use Nowo\UptimeMonitorBundle\Audit\MonitorChangeHistory;
use Nowo\UptimeMonitorBundle\Audit\MonitorChangeOutbox;
use Nowo\UptimeMonitorBundle\Audit\MonitorChangeRecorder;
use Nowo\UptimeMonitorBundle\Audit\MonitorChangeScope;
use Nowo\UptimeMonitorBundle\Audit\MonitorChangeSource;
use Nowo\UptimeMonitorBundle\Audit\MonitorFieldChange;
use Nowo\UptimeMonitorBundle\Command\PurgeMonitorChangesCommand;
use Nowo\UptimeMonitorBundle\Doctrine\UptimeEntityManager;
use Nowo\UptimeMonitorBundle\Entity\Monitor;
use Nowo\UptimeMonitorBundle\Entity\MonitorChange;
use Nowo\UptimeMonitorBundle\Entity\Tenant;
use Nowo\UptimeMonitorBundle\Enum\MonitorType;
use Nowo\UptimeMonitorBundle\Event\MonitorChangedEvent;
use Nowo\UptimeMonitorBundle\Form\Model\MonitorFormData;
use Nowo\UptimeMonitorBundle\Limits\ConfigurableTenantLimits;
use Nowo\UptimeMonitorBundle\Limits\TenantQuota;
use Nowo\UptimeMonitorBundle\Repository\MonitorRepository;
use Nowo\UptimeMonitorBundle\Service\MonitorBackupService;
use Nowo\UptimeMonitorBundle\Service\MonitorFactory;
use Nowo\UptimeMonitorBundle\Tests\Support\RecordingLogger;
use Nowo\UptimeMonitorBundle\Tests\Support\SqliteEntityManagerFactory;
use Nowo\UptimeMonitorBundle\Transaction\UptimeTransactionRunner;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\EventDispatcher\EventDispatcher;

use function array_map;
use function json_encode;

use const JSON_THROW_ON_ERROR;

/**
 * 1.33 (Beacon request after GHSA-fv2q-mqc7-6vgh): the audit trail of monitor changes on a real database (SQLite by
 * default, MySQL / MariaDB / PostgreSQL with UPTIME_TEST_DATABASE_URL).
 */
#[Group('db')]
#[CoversClass(MonitorChangeRecorder::class)]
#[CoversClass(MonitorChangeCapture::class)]
#[CoversClass(MonitorChangeScope::class)]
#[CoversClass(MonitorChangeOutbox::class)]
#[CoversClass(MonitorChangeHistory::class)]
#[CoversClass(MonitorAuditState::class)]
#[CoversClass(PurgeMonitorChangesCommand::class)]
#[CoversClass(UptimeTransactionRunner::class)]
#[CoversClass(MonitorChange::class)]
#[CoversClass(MonitorChangedEvent::class)]
#[CoversClass(MonitorFieldChange::class)]
final class MonitorChangeAuditDbTest extends TestCase
{
    /** Values that must never reach an event or a history row. */
    private const SECRETS = ['hunter2', 's3cret-token', 'abc123', 'pw-secret', 'payload-secret', 'proxypass', 'proxyuser', 'bob-the-user'];

    private EntityManagerInterface $em;
    private MonitorRepository $monitors;
    private MonitorFactory $factory;
    private CommitLedger $ledger;
    private MockClock $clock;
    private Tenant $tenant;

    /** @var list<MonitorChangedEvent> */
    private array $events = [];

    /** @var list<bool> Whether the bundle connection was inside a transaction when each event arrived */
    private array $inTransaction = [];

    protected function setUp(): void
    {
        $this->em = SqliteEntityManagerFactory::create();
        $registry = $this->createStub(ManagerRegistry::class);
        $registry->method('getManagerForClass')->willReturn($this->em);
        $this->monitors = new MonitorRepository($registry);
        $this->factory  = new MonitorFactory($this->monitors);
        $this->ledger   = new CommitLedger();
        $this->clock    = new MockClock('2026-11-20 10:00:00 UTC');
        $this->tenant   = new Tenant('main', 'Main');
        $this->em->persist($this->tenant);
        $this->em->flush();
    }

    private function recorder(bool $history = true, ?MonitorChangeActorResolverInterface $actor = null, ?EventDispatcher $dispatcher = null, ?RecordingLogger $logger = null): MonitorChangeRecorder
    {
        if (!$dispatcher instanceof EventDispatcher) {
            $dispatcher = new EventDispatcher();
            $dispatcher->addListener(MonitorChangedEvent::class, function (MonitorChangedEvent $event): void {
                $this->events[]        = $event;
                $this->inTransaction[] = $this->em->getConnection()->isTransactionActive();
            });
        }

        return new MonitorChangeRecorder(
            true,
            $history,
            $actor ?? new class implements MonitorChangeActorResolverInterface {
                public function actorId(): string
                {
                    return 'alice@example.test';
                }
            },
            $dispatcher,
            new MonitorChangeOutbox($this->ledger),
            $this->clock,
            $logger ?? new RecordingLogger(),
        );
    }

    private function runner(?MonitorChangeRecorder $recorder = null, string $source = MonitorChangeSource::UI): UptimeTransactionRunner
    {
        return (new UptimeTransactionRunner($this->em, backoffBaseMilliseconds: 0, audit: $recorder ?? $this->recorder()))->withAuditSource($source);
    }

    /**
     * @return callable(): void
     */
    private function persisting(object $entity): callable
    {
        return function () use ($entity): void {
            $this->em->persist($entity);
        };
    }

    /**
     * @return callable(): void
     */
    private function removing(object $entity): callable
    {
        return function () use ($entity): void {
            $this->em->remove($entity);
        };
    }

    /**
     * The events dispatched so far (read again, not narrowed).
     *
     * @return list<MonitorChangedEvent>
     */
    private function dispatched(): array
    {
        return $this->events;
    }

    private function httpForm(): MonitorFormData
    {
        $data                  = new MonitorFormData();
        $data->name            = 'API';
        $data->type            = MonitorType::Https;
        $data->url             = 'https://user:hunter2@api.example.test/health?token=s3cret-token';
        $data->method          = 'GET';
        $data->httpHeaders     = 'Authorization: Bearer abc123';
        $data->authMethod      = 'basic';
        $data->authUsername    = 'bob-the-user';
        $data->authPassword    = 'pw-secret';
        $data->httpBody        = 'payload-secret';
        $data->intervalSeconds = 60;

        return $data;
    }

    /**
     * @return array<string, MonitorFieldChange>
     */
    private static function byField(MonitorChangedEvent $event): array
    {
        $changes = [];
        foreach ($event->changes as $change) {
            $changes[$change->field] = $change;
        }

        return $changes;
    }

    /**
     * @return list<MonitorChange>
     */
    private function rows(): array
    {
        $this->em->clear();

        return $this->em->getRepository(MonitorChange::class)->findBy([], ['id' => 'ASC']);
    }

    private function assertNoSecretLeaks(): void
    {
        $dump = json_encode([
            array_map(static fn (MonitorChangedEvent $event): array => [$event->changesAsArrays(), $event->actorId], $this->events),
            array_map(static fn (MonitorChange $row): array => [$row->getActor(), array_map(static fn (MonitorFieldChange $change): array => $change->toArray(), $row->getChanges())], $this->rows()),
        ], JSON_THROW_ON_ERROR);
        foreach (self::SECRETS as $secret) {
            self::assertStringNotContainsString($secret, $dump);
        }
    }

    public function testCreateAndEditRecordWhoWhenAndTheRouteFieldsWithSecretsAsMarkersOnly(): void
    {
        $runner  = $this->runner();
        $monitor = $this->factory->createFromFormData($this->tenant, $this->httpForm());
        $runner->run($this->persisting($monitor));

        self::assertCount(1, $this->events);
        $created = $this->events[0];
        self::assertSame(['main', (int) $monitor->getId(), 'created', 'alice@example.test', 'ui'], [$created->tenantSlug, $created->monitorId, $created->action, $created->actorId, $created->source]);
        self::assertEquals(new DateTimeImmutable('2026-11-20 10:00:00 UTC'), $created->occurredAt);
        $fields = self::byField($created);
        self::assertSame([null, 'https://api.example.test'], [$fields['target']->before, $fields['target']->after]);
        foreach (['target_details', 'headers', 'auth_username', 'auth_password', 'body'] as $secret) {
            self::assertSame(MonitorFieldChange::REPLACED, $fields[$secret]->secret, $secret);
        }

        // The edit: another host, a proxy with credentials, TLS verification off, another method, interval and pause.
        $data                  = $this->factory->toFormData($monitor);
        $data->url             = 'https://other.example.test/health';
        $data->proxy           = 'http://proxyuser:proxypass@proxy.example.test:3128';
        $data->ignoreTls       = true;
        $data->method          = 'POST';
        $data->intervalSeconds = 120;
        $data->paused          = true;
        $this->clock->modify('+5 minutes');
        $runner->run(function () use ($monitor, $data): void {
            $this->factory->applyFormData($monitor, $data);
        });

        $events = $this->dispatched();
        self::assertCount(2, $events);
        $fields = self::byField($events[1]);
        self::assertSame('updated', $events[1]->action);
        self::assertSame(['https://api.example.test', 'https://other.example.test'], [$fields['target']->before, $fields['target']->after]);
        self::assertSame([null, 'http://proxy.example.test:3128'], [$fields['proxy']->before, $fields['proxy']->after]);
        self::assertSame([false, true], [$fields['ignore_tls']->before, $fields['ignore_tls']->after]);
        self::assertSame(['GET', 'POST'], [$fields['method']->before, $fields['method']->after]);
        self::assertSame([60, 120], [$fields['interval_seconds']->before, $fields['interval_seconds']->after]);
        self::assertSame([true, false], [$fields['enabled']->before, $fields['enabled']->after]);
        self::assertSame(MonitorFieldChange::REPLACED, $fields['target_details']->secret);
        self::assertSame(MonitorFieldChange::REPLACED, $fields['proxy_details']->secret);
        // Another destination: the stored header, password and body did not follow (1.25.1 / 1.32).
        foreach (['headers', 'auth_password', 'body'] as $secret) {
            self::assertSame(MonitorFieldChange::CLEARED, $fields[$secret]->secret, $secret);
        }
        self::assertArrayNotHasKey('auth_username', $fields);
        self::assertArrayNotHasKey('name', $fields);

        // Dispatched after the commit, and the history row was visible by then.
        self::assertSame([false, false], $this->inTransaction);
        $rows = $this->rows();
        self::assertCount(2, $rows);
        self::assertSame(['created', 'updated'], [$rows[0]->getAction(), $rows[1]->getAction()]);
        self::assertSame([(int) $monitor->getId(), 'main', 'ui', 'alice@example.test'], [$rows[1]->getMonitorId(), $rows[1]->getTenant()->getSlug(), $rows[1]->getSource(), $rows[1]->getActor()]);
        self::assertEquals(new DateTimeImmutable('2026-11-20 10:05:00 UTC'), $rows[1]->getOccurredAt());
        self::assertEquals($events[1]->changes, $rows[1]->getChanges());
        self::assertNotNull($rows[1]->getId());
        $this->assertNoSecretLeaks();
    }

    public function testACheckStateOnlyFlushRecordsNothing(): void
    {
        $monitor = new Monitor($this->tenant, 'Site', MonitorType::Https, 'https://site.test');
        $this->runner()->run($this->persisting($monitor));
        $this->events = [];

        $this->runner()->run(static fn (): Monitor => $monitor->setNextCheckAt(new DateTimeImmutable('2026-11-21'))->setConfig($monitor->getConfig() + ['failure_streak' => 3]));

        self::assertSame([], $this->events);
        self::assertCount(1, $this->rows());
    }

    public function testPauseToggleDeleteAndPushTokenAreRecorded(): void
    {
        $monitor = new Monitor($this->tenant, 'Agent', MonitorType::Push, 'push');
        $monitor->setPushKey('key-1')->setPushTokenHash('hash-1');
        $this->runner()->run($this->persisting($monitor));
        $id = (int) $monitor->getId();

        $this->runner()->run(static fn (): Monitor => $monitor->setPaused(true));
        $this->runner()->run(static fn (): Monitor => $monitor->setPushTokenHash(null));
        $this->runner()->run($this->removing($monitor));

        self::assertSame(['created', 'updated', 'updated', 'deleted'], array_map(static fn (MonitorChangedEvent $event): string => $event->action, $this->events));
        self::assertSame(['enabled'], $this->events[1]->changedFields());
        self::assertSame([['field' => 'push_token', 'secret' => 'cleared']], $this->events[2]->changesAsArrays());
        $deleted = self::byField($this->events[3]);
        self::assertSame($id, $this->events[3]->monitorId);
        self::assertSame(['Agent', null], [$deleted['name']->before, $deleted['name']->after]);
        self::assertSame(MonitorFieldChange::CLEARED, $deleted['push_url_key']->secret);
        self::assertStringNotContainsString('key-1', json_encode($this->events[0]->changesAsArrays(), JSON_THROW_ON_ERROR));
        self::assertCount(4, $this->rows());
    }

    public function testARolledBackUnitRecordsNothing(): void
    {
        try {
            $this->runner()->run(function (): never {
                $this->em->persist(new Monitor($this->tenant, 'Doomed', MonitorType::Https, 'https://doomed.test'));
                $this->em->flush();

                throw new RuntimeException('refused');
            });
        } catch (RuntimeException $failure) {
            self::assertSame('refused', $failure->getMessage());
        }

        self::assertSame([], $this->events);
        self::assertSame([], $this->rows());
    }

    public function testAReplayedUnitDispatchesOnceWithTheCommittedValues(): void
    {
        $attempt = 0;
        $this->runner()->runRetrying(function () use (&$attempt): void {
            ++$attempt;
            $tenant = $this->em->find(Tenant::class, $this->tenant->getId());
            self::assertInstanceOf(Tenant::class, $tenant);
            $this->em->persist(new Monitor($tenant, 'Attempt ' . $attempt, MonitorType::Https, 'https://replayed.test'));
            if ($attempt === 1) {
                $this->em->flush();

                throw new TransientStorageFailure(0);
            }
        });

        self::assertSame(2, $attempt);
        self::assertCount(1, $this->events);
        self::assertSame(['Attempt 2'], [self::byField($this->events[0])['name']->after]);
        self::assertCount(1, $this->rows());
    }

    public function testAFailedNestedRunIsForgottenAndTheOuterOneRecorded(): void
    {
        $runner = $this->runner();
        $runner->run(function () use ($runner): void {
            try {
                $runner->run(function (): never {
                    $this->em->persist(new Monitor($this->tenant, 'Inner', MonitorType::Https, 'https://inner.test'));
                    $this->em->flush();

                    throw new RuntimeException('inner refused');
                });
            } catch (RuntimeException) {
            }
            $tenant = $this->em->find(Tenant::class, $this->tenant->getId());
            self::assertInstanceOf(Tenant::class, $tenant);
            // A nested run that succeeds shares the outer capture.
            $runner->run($this->persisting(new Monitor($tenant, 'Outer', MonitorType::Https, 'https://outer.test')));
        });

        self::assertSame(['Outer'], array_map(static fn (MonitorChangedEvent $event): mixed => self::byField($event)['name']->after, $this->events));
        self::assertCount(1, $this->rows());
    }

    public function testARunInsideAnApplicationTransactionWaitsForItsCommit(): void
    {
        $recorder   = $this->recorder();
        $connection = $this->em->getConnection();

        $connection->beginTransaction();
        $this->runner($recorder)->run($this->persisting(new Monitor($this->tenant, 'Waits', MonitorType::Https, 'https://waits.test')));
        self::assertSame([], $this->events);
        $recorder->releaseDeferred(false);
        self::assertSame([], $this->events, 'still inside the application transaction');
        $connection->commit();
        $this->ledger->record();
        $recorder->releaseDeferred(false);
        self::assertCount(1, $this->dispatched());
        self::assertSame([false], $this->inTransaction);

        // Rolled back: the change and its event are gone.
        $connection->beginTransaction();
        $this->runner($recorder)->run($this->persisting(new Monitor($this->tenant, 'Rolled back', MonitorType::Https, 'https://rolled.test')));
        $connection->rollBack();
        $recorder->releaseDeferred(false);
        self::assertCount(1, $this->dispatched());
        self::assertCount(1, $this->rows());
    }

    public function testWithoutHistoryOnlyTheEventIsDispatchedAndAListenerFailureIsLogged(): void
    {
        $dispatcher = new EventDispatcher();
        $dispatcher->addListener(MonitorChangedEvent::class, function (MonitorChangedEvent $event): void {
            $this->events[] = $event;
        });
        $dispatcher->addListener(MonitorChangedEvent::class, static function (): never {
            throw new RuntimeException('listener down');
        });
        $logger   = new RecordingLogger();
        $recorder = $this->recorder(false, new class implements MonitorChangeActorResolverInterface {
            public function actorId(): string
            {
                return '';
            }
        }, $dispatcher, $logger);
        self::assertFalse($recorder->isHistoryEnabled());

        $this->runner($recorder, MonitorChangeSource::APP)->run($this->persisting(new Monitor($this->tenant, 'No history', MonitorType::Https, 'https://n.test')));

        self::assertSame([], $this->rows());
        self::assertSame([null, 'app'], [$this->events[0]->actorId, $this->events[0]->source]);
        self::assertSame('error', $logger->records[0]['level']);
        self::assertStringContainsString('listener failed', $logger->records[0]['message']);
        self::assertSame(RuntimeException::class, $logger->records[0]['context']['exception_class']);
    }

    public function testTheBackupImportIsRecordedAsImport(): void
    {
        $service = new MonitorBackupService($this->monitors, $this->factory, $this->em, transactionRunner: new UptimeTransactionRunner($this->em, audit: $this->recorder()));
        $service->import($this->tenant, ['monitors' => [['name' => 'Imported', 'target' => 'https://imported.test']]]);
        $quoted = new MonitorBackupService($this->monitors, $this->factory, $this->em, quota: new TenantQuota(new ConfigurableTenantLimits(0, 30), $this->monitors), transactionRunner: new UptimeTransactionRunner($this->em, audit: $this->recorder()));
        $quoted->import($this->tenant, ['monitors' => [['name' => 'Imported under quota', 'target' => 'https://quota.test']]]);

        self::assertSame([['created', 'import'], ['created', 'import']], array_map(static fn (MonitorChangedEvent $event): array => [$event->action, $event->source], $this->events));
    }

    public function testTheHistoryIsShownNewestFirstPurgedByRetentionAndActorsAnonymised(): void
    {
        $recorder = $this->recorder();
        $monitor  = new Monitor($this->tenant, 'Kept', MonitorType::Https, 'https://kept.test');
        $this->runner($recorder)->run($this->persisting($monitor));
        $this->clock->modify('+400 days');
        $this->runner($recorder)->run(static fn (): Monitor => $monitor->setIntervalSeconds(300));
        $this->runner($recorder)->run(static fn (): Monitor => $monitor->setIntervalSeconds(600));

        $history = new MonitorChangeHistory($this->em, $recorder, 365, 2, 1, $this->clock);
        self::assertTrue($history->isEnabled());
        $shown = $history->forMonitor($monitor);
        self::assertCount(2, $shown);
        self::assertSame([600, 300], array_map(static fn (MonitorChange $row): mixed => $row->getChanges()[0]->after, $shown));
        self::assertSame([], $history->forMonitor(new Monitor($this->tenant, 'Unsaved', MonitorType::Https, 'https://u.test')));
        self::assertSame([], (new MonitorChangeHistory($this->em, new MonitorChangeRecorder()))->forMonitor($monitor));

        $tester = new CommandTester(new PurgeMonitorChangesCommand($history));
        self::assertSame(Command::SUCCESS, $tester->execute([]));
        self::assertStringContainsString('Purged 1 monitor change(s).', $tester->getDisplay());
        self::assertSame(0, (new MonitorChangeHistory($this->em, $recorder, 0))->purge(), 'retention 0 keeps everything');

        self::assertSame(Command::SUCCESS, $tester->execute(['--anonymise-actor' => 'alice@example.test']));
        self::assertStringContainsString('Anonymised 2 monitor change(s).', $tester->getDisplay());
        self::assertSame([MonitorChange::ANONYMISED_ACTOR, MonitorChange::ANONYMISED_ACTOR], array_map(static fn (MonitorChange $row): ?string => $row->getActor(), $this->rows()));
        self::assertSame(Command::INVALID, $tester->execute(['--anonymise-actor' => '']));
    }

    public function testThePrivateManagerIsRecordedAndTheApplicationManagerSharingItsEventsIsNot(): void
    {
        $registry = $this->createStub(ManagerRegistry::class);
        $registry->method('getManager')->willReturn($this->em);
        $registry->method('getManagerForClass')->willReturn($this->em);
        $registry->method('getConnection')->willReturn($this->em->getConnection());
        $private = new UptimeEntityManager($registry, 'default', 'default');
        $runner  = new UptimeTransactionRunner($private, audit: $this->recorder());

        $runner->run(function () use ($private): void {
            $tenant = $private->find(Tenant::class, $this->tenant->getId());
            self::assertInstanceOf(Tenant::class, $tenant);
            $private->persist(new Monitor($tenant, 'Private', MonitorType::Https, 'https://private.test'));
            // The application's manager shares the event manager: its flush is not the bundle's.
            $this->em->persist(new Monitor($this->tenant, 'Application', MonitorType::Https, 'https://app.test'));
            $this->em->flush();
        });

        self::assertSame(['Private'], array_map(static fn (MonitorChangedEvent $event): mixed => self::byField($event)['name']->after, $this->events));
    }

    public function testAGeneratorThatIsNotPostInsertGivesTheHistoryIdFirst(): void
    {
        $this->em->getClassMetadata(MonitorChange::class)->setIdGenerator(new class extends AbstractIdGenerator {
            public function generateId(EntityManagerInterface $em, ?object $entity): mixed
            {
                return 4242;
            }
        });

        $this->runner()->run($this->persisting(new Monitor($this->tenant, 'Sequenced', MonitorType::Https, 'https://seq.test')));

        self::assertSame([4242], array_map(static fn (MonitorChange $row): ?int => $row->getId(), $this->rows()));
    }
}
