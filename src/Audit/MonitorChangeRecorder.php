<?php

declare(strict_types=1);

namespace Nowo\UptimeMonitorBundle\Audit;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Events;
use Nowo\UptimeMonitorBundle\Entity\MonitorChange;
use Nowo\UptimeMonitorBundle\Entity\Tenant;
use Nowo\UptimeMonitorBundle\Event\MonitorChangedEvent;
use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\Clock\NativeClock;
use Throwable;

use function mb_substr;

/**
 * The audit trail of monitor changes (since 1.33, `audit.enabled`, off by default).
 *
 * The bundle's transaction runner opens a {@see MonitorChangeScope} per run ({@see self::open()}); the capture of the
 * outermost run records every monitor the bundle's manager inserts, updates or deletes, whatever the write path. Each
 * change resolves its actor ({@see MonitorChangeActorResolverInterface}), writes a history row when
 * `audit.history.enabled` (inside the same transaction, {@see self::record()}), and becomes a
 * {@see MonitorChangedEvent} dispatched once the run committed ({@see self::release()}). When the bundle's run sits in
 * a transaction the application opened (a write unit around a bundle route, a host transaction), the events wait in
 * {@see MonitorChangeOutbox} until that transaction ends.
 *
 * A history insert that fails fails the change with it (no unaudited write). A listener that throws after the commit is
 * logged with its class only and does not fail the request: the change is committed.
 *
 * Stateless (FrankenPHP worker mode, kernel reset off): every run carries its own capture.
 */
final readonly class MonitorChangeRecorder
{
    /** Longest actor identifier stored. */
    public const MAX_ACTOR_LENGTH = 255;

    /**
     * @param bool $enabled `audit.enabled`
     * @param bool $historyEnabled `audit.history.enabled` (also needs `audit.enabled`)
     * @param MonitorChangeActorResolverInterface|null $actorResolver Who changes monitors (null: no actor)
     * @param EventDispatcherInterface|null $eventDispatcher Receives {@see MonitorChangedEvent}
     * @param MonitorChangeOutbox|null $outbox Holds the events of a run nested in an application transaction
     * @param ClockInterface $clock When each change is written
     * @param LoggerInterface $logger Logs a failing listener (class only)
     */
    public function __construct(
        private bool $enabled = false,
        private bool $historyEnabled = false,
        private ?MonitorChangeActorResolverInterface $actorResolver = null,
        private ?EventDispatcherInterface $eventDispatcher = null,
        private ?MonitorChangeOutbox $outbox = null,
        private ClockInterface $clock = new NativeClock(),
        private LoggerInterface $logger = new NullLogger(),
    ) {
    }

    /**
     * Whether the audit records anything.
     */
    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    /**
     * Whether the history table is written (and shown on the monitor page).
     */
    public function isHistoryEnabled(): bool
    {
        return $this->enabled && $this->historyEnabled;
    }

    /**
     * Opens the audit scope of one run on `$manager`: the outermost run attaches a new capture, a nested run (a capture
     * of the same manager is attached already) shares it. Null when the audit is off.
     *
     * @param string $source Write path ({@see MonitorChangeSource})
     */
    public function open(EntityManagerInterface $manager, string $source): ?MonitorChangeScope
    {
        if (!$this->enabled) {
            return null;
        }

        $eventManager = $manager->getEventManager();
        foreach ($eventManager->getListeners(Events::onFlush) as $listener) {
            if ($listener instanceof MonitorChangeCapture && $listener->records($manager)) {
                return new MonitorChangeScope($listener, $eventManager, $manager->getConnection(), $this, false, $listener->mark());
            }
        }

        $capture = new MonitorChangeCapture($manager, $this, $source);
        $eventManager->addEventListener(MonitorChangeCapture::events(), $capture);

        return new MonitorChangeScope($capture, $eventManager, $manager->getConnection(), $this, true, 0);
    }

    /**
     * Records one change inside the open transaction: the actor, the time, and the history row when enabled.
     *
     * @param EntityManagerInterface $manager The manager that flushed the change
     * @param list<MonitorFieldChange> $changes
     */
    public function record(EntityManagerInterface $manager, Tenant $tenant, int $monitorId, string $action, array $changes, string $source): MonitorChangedEvent
    {
        $actor = $this->actorResolver?->actorId();
        $event = new MonitorChangedEvent(
            $tenant->getSlug(),
            $monitorId,
            $action,
            $changes,
            $actor === null || $actor === '' ? null : mb_substr($actor, 0, self::MAX_ACTOR_LENGTH),
            $source,
            $this->clock->now(),
        );
        if ($this->historyEnabled) {
            $this->insertHistory($manager, $tenant, $event);
        }

        return $event;
    }

    /**
     * Dispatches the changes of a run that committed; when the connection is still inside a transaction (one the
     * application opened around the run), they wait in the outbox until it ends.
     *
     * @param list<MonitorChangedEvent> $events
     */
    public function release(array $events, Connection $connection): void
    {
        if ($events === []) {
            return;
        }
        if ($this->outbox instanceof MonitorChangeOutbox && $connection->isTransactionActive()) {
            $this->outbox->defer($events, $connection);

            return;
        }

        $this->dispatch($events);
    }

    /**
     * Dispatches the waiting changes whose transaction committed, at a unit-of-work boundary (`$failed`: the unit ended
     * with an exception).
     */
    public function releaseDeferred(bool $failed): void
    {
        if ($this->outbox instanceof MonitorChangeOutbox) {
            $this->dispatch($this->outbox->takeReady($failed));
        }
    }

    /**
     * @param list<MonitorChangedEvent> $events
     */
    private function dispatch(array $events): void
    {
        foreach ($events as $event) {
            try {
                $this->eventDispatcher?->dispatch($event);
            } catch (Throwable $failure) {
                $this->logger->error('Uptime monitor: a MonitorChangedEvent listener failed after the commit ({exception_class}); the change is committed.', [
                    'bundle'          => 'NowoUptimeMonitorBundle',
                    'exception_class' => $failure::class,
                    'monitor_id'      => $event->monitorId,
                ]);
            }
        }
    }

    /**
     * Inserts the history row through the manager's connection (inside its transaction). A generator that is not a
     * post-insert one (a sequence) gives the id first.
     */
    private function insertHistory(EntityManagerInterface $manager, Tenant $tenant, MonitorChangedEvent $event): void
    {
        $metadata = $manager->getClassMetadata(MonitorChange::class);
        $data     = [
            $metadata->getSingleAssociationJoinColumnName('tenant') => $tenant->getId(),
            $metadata->getColumnName('monitorId')                   => $event->monitorId,
            $metadata->getColumnName('action')                      => $event->action,
            $metadata->getColumnName('source')                      => $event->source,
            $metadata->getColumnName('actor')                       => $event->actorId,
            $metadata->getColumnName('changes')                     => $event->changesAsArrays(),
            $metadata->getColumnName('occurredAt')                  => $event->occurredAt,
        ];
        $types = [Types::INTEGER, Types::INTEGER, Types::STRING, Types::STRING, Types::STRING, Types::JSON, Types::DATETIME_IMMUTABLE];
        if (!$metadata->idGenerator->isPostInsertGenerator()) {
            $data  = [$metadata->getSingleIdentifierColumnName() => $metadata->idGenerator->generateId($manager, null)] + $data;
            $types = [Types::INTEGER, ...$types];
        }

        $manager->getConnection()->insert($metadata->getTableName(), $data, $types);
    }
}
