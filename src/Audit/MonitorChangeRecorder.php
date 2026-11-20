<?php

declare(strict_types=1);

namespace Nowo\UptimeMonitorBundle\Audit;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Events;
use InvalidArgumentException;
use Nowo\UptimeMonitorBundle\Entity\MonitorChange;
use Nowo\UptimeMonitorBundle\Entity\Tenant;
use Nowo\UptimeMonitorBundle\Event\MonitorChangedEvent;
use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\Clock\NativeClock;
use Throwable;

use function count;
use function mb_substr;
use function sprintf;

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

        return new MonitorChangeScope($capture, $eventManager, $manager->getConnection(), $this, true, 0, $this->historyEnabled ? $this->historyLocation($manager) : []);
    }

    /**
     * Records one change inside the open transaction: the actor, the time, and the history row when enabled.
     *
     * @param EntityManagerInterface $manager The manager that flushed the change
     * @param list<MonitorFieldChange> $changes
     * @param string $source Write path ({@see MonitorChangeSource::PATTERN})
     * @param int|null $historyId Receives the id of the history row (null without the history)
     *
     * @throws InvalidArgumentException When the source is not a valid write path name
     */
    public function record(EntityManagerInterface $manager, Tenant $tenant, int $monitorId, string $action, array $changes, string $source, ?int &$historyId = null): MonitorChangedEvent
    {
        MonitorChangeSource::assertValid($source);
        $historyId = null;
        $actor     = $this->actorResolver?->actorId();
        $event     = new MonitorChangedEvent(
            $tenant->getSlug(),
            $monitorId,
            $action,
            $changes,
            $actor === null || $actor === '' ? null : mb_substr($actor, 0, self::MAX_ACTOR_LENGTH),
            $source,
            $this->clock->now(),
        );
        if ($this->historyEnabled) {
            $historyId = $this->insertHistory($manager, $tenant, $event);
        }

        return $event;
    }

    /**
     * Dispatches the changes of a run that committed; when the connection is still inside a transaction (one the
     * application opened around the run), they wait in the outbox until it ends.
     *
     * @param list<MonitorChangedEvent> $events
     * @param list<int> $historyIds The history rows the run inserted (empty without the history): looked up to tell
     *                              whether the application's transaction committed
     * @param string|null $historyTable Their table
     * @param string|null $historyIdColumn Their id column
     */
    public function release(array $events, Connection $connection, array $historyIds = [], ?string $historyTable = null, ?string $historyIdColumn = null): void
    {
        if ($events === []) {
            return;
        }
        if ($this->outbox instanceof MonitorChangeOutbox && $connection->isTransactionActive()) {
            $confirm = $historyIds !== [] && $historyTable !== null && $historyIdColumn !== null
                ? fn (): bool => $this->historyRowsExist($connection, $historyTable, $historyIdColumn, $historyIds)
                : null;
            $this->outbox->defer($events, $connection, $confirm);

            return;
        }

        $this->dispatch($events);
    }

    /**
     * Dispatches the waiting changes whose transaction committed, at a unit-of-work boundary.
     *
     * @param bool $failed The unit met an exception
     * @param bool $endOfUnit The unit is over (changes still inside a transaction are dropped)
     */
    public function releaseDeferred(bool $failed, bool $endOfUnit = true): void
    {
        if ($this->outbox instanceof MonitorChangeOutbox) {
            $this->dispatch($this->outbox->takeReady($failed, $endOfUnit));
        }
    }

    /**
     * Whether every history row of a run is in the database (its transaction committed). A failing lookup counts as
     * not committed (logged with its class only): no event rather than a phantom one.
     *
     * @param list<int> $ids
     */
    private function historyRowsExist(Connection $connection, string $table, string $idColumn, array $ids): bool
    {
        try {
            $found = $connection->fetchOne(
                sprintf('SELECT COUNT(*) FROM %s WHERE %s IN (?)', $table, $idColumn),
                [$ids],
                [ArrayParameterType::INTEGER],
            );
        } catch (Throwable $failure) {
            $this->logger->error('Uptime monitor: the history rows of deferred monitor changes could not be read ({exception_class}); their events are dropped.', [
                'bundle'          => 'NowoUptimeMonitorBundle',
                'exception_class' => $failure::class,
            ]);

            return false;
        }

        return (int) $found === count($ids);
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
     *
     * @return int The id of the row
     */
    private function insertHistory(EntityManagerInterface $manager, Tenant $tenant, MonitorChangedEvent $event): int
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
        $id    = null;
        if (!$metadata->idGenerator->isPostInsertGenerator()) {
            $id    = (int) $metadata->idGenerator->generateId($manager, null);
            $data  = [$metadata->getSingleIdentifierColumnName() => $id] + $data;
            $types = [Types::INTEGER, ...$types];
        }

        $connection = $manager->getConnection();
        $connection->insert($metadata->getTableName(), $data, $types);

        return $id ?? (int) $connection->lastInsertId();
    }

    /**
     * The table and id column of the history (the outbox looks the rows up by id).
     *
     * @return array{0: string, 1: string}
     */
    public function historyLocation(EntityManagerInterface $manager): array
    {
        $metadata = $manager->getClassMetadata(MonitorChange::class);

        return [$metadata->getTableName(), $metadata->getSingleIdentifierColumnName()];
    }
}
