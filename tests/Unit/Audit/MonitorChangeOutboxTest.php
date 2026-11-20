<?php

declare(strict_types=1);

namespace Nowo\UptimeMonitorBundle\Tests\Unit\Audit;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Nowo\TransactionDoctrine\WriteUnit\CommitLedger;
use Nowo\UptimeMonitorBundle\Audit\MonitorChangeOutbox;
use Nowo\UptimeMonitorBundle\Audit\MonitorChangeOutboxListener;
use Nowo\UptimeMonitorBundle\Audit\MonitorChangeRecorder;
use Nowo\UptimeMonitorBundle\Audit\TokenStorageActorResolver;
use Nowo\UptimeMonitorBundle\Event\MonitorChangedEvent;
use Nowo\UptimeMonitorBundle\Tests\Support\RecordingLogger;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorage;
use Symfony\Component\Security\Core\Authentication\Token\SwitchUserToken;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Security\Core\User\InMemoryUser;

use function array_map;

#[CoversClass(MonitorChangeOutbox::class)]
#[CoversClass(MonitorChangeOutboxListener::class)]
#[CoversClass(MonitorChangeRecorder::class)]
#[CoversClass(TokenStorageActorResolver::class)]
#[CoversClass(MonitorChangedEvent::class)]
final class MonitorChangeOutboxTest extends TestCase
{
    private function event(int $id = 1): MonitorChangedEvent
    {
        return new MonitorChangedEvent('main', $id, MonitorChangedEvent::ACTION_UPDATED, [], null, 'app', new DateTimeImmutable('2026-11-20'));
    }

    /**
     * @return list<array{level: mixed, message: string, context: array<string, mixed>}>
     */
    private function recordsOf(RecordingLogger $logger): array
    {
        return $logger->records;
    }

    private function connection(bool $inTransaction): Connection
    {
        $connection = $this->createStub(Connection::class);
        $connection->method('isTransactionActive')->willReturn($inTransaction);

        return $connection;
    }

    public function testWithoutALedgerAFailedUnitDropsAndASucceededOneReleases(): void
    {
        $outbox = new MonitorChangeOutbox();
        $outbox->defer([$this->event()], $this->connection(true));
        self::assertSame([], $outbox->takeReady(false, false), 'still inside the transaction');

        $outbox->resetBetweenAttempts();
        $outbox->defer([$this->event()], $this->connection(false));
        self::assertSame([], $outbox->takeReady(true));
        self::assertSame([], $outbox->takeReady(false), 'dropped, not kept');

        $released = $this->event(2);
        $outbox->defer([$released], $this->connection(false));
        self::assertSame([$released], $outbox->takeReady(false));
    }

    public function testAResetDropsWhatIsStillWaitingWithAWarning(): void
    {
        $quiet = new RecordingLogger();
        (new MonitorChangeOutbox(null, $quiet))->reset();
        self::assertSame([], $quiet->records);

        $logger = new RecordingLogger();
        $outbox = new MonitorChangeOutbox(null, $logger);
        $outbox->defer([$this->event()], $this->connection(true));
        $outbox->reset();
        self::assertSame('warning', $logger->records[0]['level']);
        self::assertSame(1, $logger->records[0]['context']['count']);
        self::assertSame([], $outbox->takeReady(false));
    }

    /**
     * Audit VU-M1 (repro): a host transaction rolled back (and caught), then any other commit of the request. With the
     * history the rows are looked up and the change is dropped; without it, a failure seen since the defer is sticky.
     */
    public function testARolledBackHostTransactionReleasesNoPhantomEvent(): void
    {
        $inTransaction = true;
        $connection    = $this->createStub(Connection::class);
        $connection->method('isTransactionActive')->willReturnCallback(static function () use (&$inTransaction): bool {
            return $inTransaction;
        });
        $ledger = new CommitLedger();

        $withHistory = new MonitorChangeOutbox($ledger);
        $withHistory->defer([$this->event(1)], $connection, static fn (): bool => false);
        $inTransaction = false;
        $ledger->record();
        self::assertSame([], $withHistory->takeReady(false), 'history rows missing: rolled back');

        $withoutHistory = new MonitorChangeOutbox($ledger);
        $inTransaction  = true;
        $withoutHistory->defer([$this->event(2)], $connection);
        $withoutHistory->takeReady(true, false);
        $inTransaction = false;
        $ledger->record();
        self::assertSame([], $withoutHistory->takeReady(false), 'the failure is sticky');
    }

    /**
     * Audit VU-M2 (repro): the replay of a second write unit keeps the committed changes of the first one.
     */
    public function testAReplayKeepsTheCommittedChangesOfAnEarlierUnit(): void
    {
        $inTransaction = true;
        $connection    = $this->createStub(Connection::class);
        $connection->method('isTransactionActive')->willReturnCallback(static function () use (&$inTransaction): bool {
            return $inTransaction;
        });
        $ledger = new CommitLedger();
        $outbox = new MonitorChangeOutbox($ledger);

        $outbox->defer([$this->event(1)], $connection);
        $inTransaction = false;
        $ledger->record();
        $inTransaction = true;
        $outbox->defer([$this->event(2)], $connection);
        $inTransaction = false;
        $outbox->resetBetweenAttempts();
        $inTransaction = true;
        $outbox->defer([$this->event(3)], $connection);
        $inTransaction = false;
        $ledger->record();

        self::assertSame([1, 3], array_map(static fn (MonitorChangedEvent $event): int => $event->monitorId, $outbox->takeReady(false)));
    }

    /**
     * Audit VU2-L1b: without the history and without a ledger a replay cannot tell an earlier unit's commit from the
     * failed attempt: it drops the waiting change instead of releasing it (and then the replay's duplicate).
     */
    public function testAReplayWithoutLedgerNorHistoryDropsTheWaitingChange(): void
    {
        $outbox = new MonitorChangeOutbox();
        $outbox->defer([$this->event(1)], $this->connection(false));
        $outbox->resetBetweenAttempts();
        $outbox->defer([$this->event(2)], $this->connection(false));

        self::assertSame([2], array_map(static fn (MonitorChangedEvent $event): int => $event->monitorId, $outbox->takeReady(false)));
    }

    /**
     * Audit VU-L1: a change still inside a transaction when the unit ends is dropped with a warning (a worker would carry
     * it into the next request); kernel.exception keeps it.
     */
    public function testAChangeStillInsideATransactionIsDroppedAtTheEndOfTheUnit(): void
    {
        $logger = new RecordingLogger();
        $outbox = new MonitorChangeOutbox(null, $logger);
        $outbox->defer([$this->event()], $this->connection(true));
        self::assertSame([], $outbox->takeReady(true, false));
        self::assertCount(0, $logger->records, 'kept at kernel.exception');
        self::assertSame([], $outbox->takeReady(false));
        $outbox->reset();
        $records = $this->recordsOf($logger);
        self::assertCount(1, $records, 'dropped once, nothing left at the reset');
        self::assertSame(1, $records[0]['context']['count'] ?? null);
    }

    public function testTheListenerReleasesAtTheEndOfAUnitOfWork(): void
    {
        $events     = [];
        $dispatcher = new EventDispatcher();
        $dispatcher->addListener(MonitorChangedEvent::class, static function (MonitorChangedEvent $event) use (&$events): void {
            $events[] = $event->monitorId;
        });
        $outbox   = new MonitorChangeOutbox();
        $recorder = new MonitorChangeRecorder(true, false, null, $dispatcher, $outbox);
        $listener = new MonitorChangeOutboxListener($recorder);

        $outbox->defer([$this->event(1)], $this->connection(false));
        $listener->onUnitFailing();
        $outbox->defer([$this->event(4)], $this->connection(false));
        $listener->onUnitFailed();
        $outbox->defer([$this->event(2)], $this->connection(false));
        $listener->onUnitSucceeded();

        self::assertSame([2], $events);
    }

    public function testTheRecorderWithoutOutboxDispatchesAtOnceAndOffRecordsNothing(): void
    {
        $events     = [];
        $dispatcher = new EventDispatcher();
        $dispatcher->addListener(MonitorChangedEvent::class, static function (MonitorChangedEvent $event) use (&$events): void {
            $events[] = $event->monitorId;
        });
        $recorder = new MonitorChangeRecorder(true, true, null, $dispatcher);
        self::assertTrue($recorder->isEnabled());
        $recorder->release([], $this->connection(true));
        $recorder->release([$this->event(3)], $this->connection(true));
        $recorder->releaseDeferred(false);
        self::assertSame([3], $events);

        $off = new MonitorChangeRecorder(false, true);
        self::assertFalse($off->isEnabled());
        self::assertFalse($off->isHistoryEnabled());
        self::assertNull($off->open($this->createStub(EntityManagerInterface::class), 'ui'));
    }

    public function testTheDefaultActorIsTheTokenUser(): void
    {
        self::assertNull((new TokenStorageActorResolver())->actorId());
        $storage = new TokenStorage();
        self::assertNull((new TokenStorageActorResolver($storage))->actorId());
        $storage->setToken(new UsernamePasswordToken(new InMemoryUser('alice', null), 'main'));
        self::assertSame('alice', (new TokenStorageActorResolver($storage))->actorId());
        // Audit VU-L4: under switch_user the original user is recorded too.
        $original = new UsernamePasswordToken(new InMemoryUser('admin', null), 'main');
        $storage->setToken(new SwitchUserToken(new InMemoryUser('alice', null), 'main', [], $original));
        self::assertSame('admin (as alice)', (new TokenStorageActorResolver($storage))->actorId());
    }
}
