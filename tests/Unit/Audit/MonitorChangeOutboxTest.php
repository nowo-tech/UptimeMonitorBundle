<?php

declare(strict_types=1);

namespace Nowo\UptimeMonitorBundle\Tests\Unit\Audit;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
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
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Security\Core\User\InMemoryUser;

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
        self::assertSame([], $outbox->takeReady(false), 'still inside the transaction');

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
    }
}
