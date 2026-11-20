<?php

declare(strict_types=1);

namespace Nowo\UptimeMonitorBundle\Audit;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Event\OnFlushEventArgs;
use Doctrine\ORM\Event\PostFlushEventArgs;
use Doctrine\ORM\Events;
use Doctrine\ORM\Mapping\ClassMetadata;
use Nowo\UptimeMonitorBundle\Doctrine\UptimeEntityManager;
use Nowo\UptimeMonitorBundle\Entity\Monitor;
use Nowo\UptimeMonitorBundle\Event\MonitorChangedEvent;

use function array_slice;
use function count;
use function is_array;

/**
 * Records the monitor changes of ONE outermost unit of work of the bundle's runner (since 1.33).
 *
 * Attached to the entity manager's event manager for the length of the unit and detached when it ends
 * ({@see MonitorChangeScope}), so nothing of a unit outlives it (FrankenPHP worker mode, kernel reset off). On every
 * flush of the bundle's manager inside the unit: `onFlush` takes the audited state of each monitor to insert, update
 * or delete (the unit of work's original data before, the entity after, {@see MonitorAuditState}); `postFlush` turns
 * them into {@see MonitorChangedEvent}s and writes the history rows ({@see MonitorChangeRecorder::record()}) while the
 * unit's transaction is still open, so a change and its history commit or roll back together.
 *
 * Flushes of any other manager sharing the event manager (the application's) are ignored.
 *
 * @internal
 */
final class MonitorChangeCapture
{
    /** The Doctrine fields of a monitor the audit reads. */
    private const FIELDS = ['name', 'type', 'target', 'config', 'intervalSeconds', 'paused', 'project', 'parent', 'pushKey', 'pushTokenHash', 'origin', 'originKey'];

    /** @var list<array{0: string, 1: array<string, array{0: int, 1: mixed}>, 2: array<string, array{0: int, 1: mixed}>, 3: Monitor, 4: int|null}> */
    private array $pending = [];

    /** @var list<MonitorChangedEvent> */
    private array $events = [];

    /**
     * @param EntityManagerInterface $target The manager the unit runs on (the bundle's private one)
     * @param MonitorChangeRecorder $recorder Resolves the actor, writes the history
     * @param string $source Write path of the unit
     */
    public function __construct(
        private readonly EntityManagerInterface $target,
        private readonly MonitorChangeRecorder $recorder,
        private readonly string $source,
    ) {
    }

    /**
     * The Doctrine events this capture listens to.
     *
     * @return list<string>
     */
    public static function events(): array
    {
        return [Events::onFlush, Events::postFlush];
    }

    /**
     * Whether this capture records the flushes of a manager (the target itself, or the live manager inside the bundle's
     * private one).
     */
    public function records(EntityManagerInterface $manager): bool
    {
        if ($manager === $this->target) {
            return true;
        }
        $target = $this->target;

        return $target instanceof UptimeEntityManager && $target->isInitialized() && $target->isOpen() && $manager === $target->live();
    }

    /**
     * Takes the audited state of the monitors the flush writes.
     */
    public function onFlush(OnFlushEventArgs $args): void
    {
        // A previous flush of the unit that failed leaves nothing behind.
        // @igor-ignore - Per-run capture, attached for one unit of work and detached when it ends (never a shared service).
        $this->pending = [];
        $manager       = $args->getObjectManager();
        if (!$this->records($manager)) {
            return;
        }

        $unitOfWork = $manager->getUnitOfWork();
        $metadata   = $manager->getClassMetadata(Monitor::class);
        foreach ($unitOfWork->getScheduledEntityInsertions() as $entity) {
            if ($entity instanceof Monitor) {
                // @igor-ignore - Per-run capture, attached for one unit of work and detached when it ends (never a shared service).
                $this->pending[] = [MonitorChangedEvent::ACTION_CREATED, [], $this->state($metadata, $entity), $entity, null];
            }
        }
        foreach ($unitOfWork->getScheduledEntityUpdates() as $entity) {
            if ($entity instanceof Monitor) {
                // The change set holds the old values (the original data already holds the new ones by now).
                $before = $this->fields($metadata, $entity);
                foreach ($unitOfWork->getEntityChangeSet($entity) as $field => $values) {
                    if (is_array($values)) {
                        $before[$field] = $values[0];
                    }
                }
                // @igor-ignore - Per-run capture, attached for one unit of work and detached when it ends (never a shared service).
                $this->pending[] = [MonitorChangedEvent::ACTION_UPDATED, MonitorAuditState::fromFields($before), $this->state($metadata, $entity), $entity, $entity->getId()];
            }
        }
        foreach ($unitOfWork->getScheduledEntityDeletions() as $entity) {
            if ($entity instanceof Monitor) {
                // The id is read now: Doctrine clears it once the row is deleted.
                // @igor-ignore - Per-run capture, attached for one unit of work and detached when it ends (never a shared service).
                $this->pending[] = [MonitorChangedEvent::ACTION_DELETED, MonitorAuditState::fromFields($unitOfWork->getOriginalEntityData($entity)), [], $entity, $entity->getId()];
            }
        }
    }

    /**
     * Records the changes of the flush that just wrote (the ids of new monitors are known now).
     */
    public function postFlush(PostFlushEventArgs $args): void
    {
        $manager = $args->getObjectManager();
        if (!$this->records($manager)) {
            return;
        }

        $pending = $this->pending;
        // @igor-ignore - Per-run capture, attached for one unit of work and detached when it ends (never a shared service).
        $this->pending = [];
        foreach ($pending as [$action, $before, $after, $monitor, $id]) {
            $changes = MonitorAuditState::diff($before, $after);
            if ($action === MonitorChangedEvent::ACTION_UPDATED && $changes === []) {
                // Only the check state changed (next check, streaks, push samples).
                continue;
            }
            // @igor-ignore - Per-run capture, attached for one unit of work and detached when it ends (never a shared service).
            $this->events[] = $this->recorder->record($manager, $monitor->getTenant(), $id ?? (int) $monitor->getId(), $action, $changes, $this->source);
        }
    }

    /**
     * How many changes are recorded so far (a nested unit's starting point).
     */
    public function mark(): int
    {
        return count($this->events);
    }

    /**
     * Forgets the changes recorded after a mark (a nested unit failed: its savepoint was rolled back with its history).
     */
    public function rollbackTo(int $mark): void
    {
        // @igor-ignore - Per-run capture, attached for one unit of work and detached when it ends (never a shared service).
        $this->events = array_slice($this->events, 0, $mark);
        // @igor-ignore - Per-run capture, attached for one unit of work and detached when it ends (never a shared service).
        $this->pending = [];
    }

    /**
     * The recorded changes, in the order they were written.
     *
     * @return list<MonitorChangedEvent>
     */
    public function recorded(): array
    {
        return $this->events;
    }

    /**
     * The audited state of a monitor entity (its current field values).
     *
     * @param ClassMetadata<Monitor> $metadata
     *
     * @return array<string, array{0: int, 1: mixed}>
     */
    private function state(ClassMetadata $metadata, Monitor $monitor): array
    {
        return MonitorAuditState::fromFields($this->fields($metadata, $monitor));
    }

    /**
     * The current values of the audited Doctrine fields of a monitor.
     *
     * @param ClassMetadata<Monitor> $metadata
     *
     * @return array<string, mixed>
     */
    private function fields(ClassMetadata $metadata, Monitor $monitor): array
    {
        $fields = [];
        foreach (self::FIELDS as $field) {
            $fields[$field] = $metadata->getFieldValue($monitor, $field);
        }

        return $fields;
    }
}
