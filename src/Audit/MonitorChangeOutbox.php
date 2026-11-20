<?php

declare(strict_types=1);

namespace Nowo\UptimeMonitorBundle\Audit;

use Closure;
use Doctrine\DBAL\Connection;
use Nowo\TransactionDoctrine\WriteUnit\CommitLedger;
use Nowo\TransactionDoctrine\WriteUnit\ResetBetweenAttemptsInterface;
use Nowo\UptimeMonitorBundle\Event\MonitorChangedEvent;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Contracts\Service\ResetInterface;

use function count;

/**
 * The monitor changes of a bundle run that sat inside a transaction the application opened (since 1.33): a write unit
 * around a bundle route or message, a host transaction around a bundle service. Their {@see MonitorChangedEvent}s wait
 * here until that transaction is over ({@see MonitorChangeOutboxListener} asks at the end of every request, message
 * and command), then go out once, or never when it was rolled back.
 *
 * Whether the application's transaction committed:
 *
 * - **with the history on** (`audit.history.enabled`), it is read from the database: the history rows the run inserted
 *   are looked up by id once the connection is out of the transaction. They exist only if it committed. Exact.
 * - **without the history**, nowo-tech/transaction-doctrine exposes no per-connection commit marker, so the outbox
 *   needs both: no failure since the change was deferred (sticky: an exception of the unit marks every waiting change),
 *   and, when NowoTransactionDoctrineBundle registers its {@see CommitLedger}, a commit of the process since then.
 *   Remaining cases: a host that rolls its transaction back **and catches the failure**, then commits something else
 *   in the same unit of work, still gets the event of the rolled-back change; without the ledger (commit detection off,
 *   or the bundle not registered) the caught rollback alone is enough. Turn the history on (or commit detection) to
 *   close them.
 *
 * At the end of a unit of work a change still inside a transaction is dropped with a warning (that transaction never
 * committed in this unit; a worker would otherwise carry it into the next request). Only `kernel.exception` (the
 * response is not sent yet) keeps it.
 *
 * Per-request state. Before a write unit replays its work ({@see ResetBetweenAttemptsInterface}), the changes that are
 * already committed (an earlier unit of the request) move to the ready list; the others are dropped (the replay records
 * them again). `kernel.reset` drops everything left, with a warning.
 */
final class MonitorChangeOutbox implements ResetInterface, ResetBetweenAttemptsInterface
{
    /** @var list<array{events: list<MonitorChangedEvent>, connection: Connection, count: int|null, confirm: (Closure(): bool)|null, failed: bool}> */
    private array $deferred = [];

    /** @var list<MonitorChangedEvent> Committed, not dispatched yet */
    private array $ready = [];

    /**
     * @param CommitLedger|null $ledger Counts the commits of the process (NowoTransactionDoctrineBundle)
     * @param LoggerInterface $logger Warns when waiting changes are dropped
     */
    public function __construct(
        private readonly ?CommitLedger $ledger = null,
        private readonly LoggerInterface $logger = new NullLogger(),
    ) {
    }

    /**
     * Holds the changes of a run until the transaction around it ends.
     *
     * @param list<MonitorChangedEvent> $events
     * @param (Closure(): bool)|null $confirm Whether the run's writes are in the database (its history rows); null
     *                                        without the history
     */
    public function defer(array $events, Connection $connection, ?Closure $confirm = null): void
    {
        // @igor-ignore - Per-request outbox, emptied by kernel.reset, at the end of every unit and before every replay.
        $this->deferred[] = ['events' => $events, 'connection' => $connection, 'count' => $this->ledger?->count(), 'confirm' => $confirm, 'failed' => false];
    }

    /**
     * The changes whose transaction is over and committed. A change still inside a transaction keeps waiting unless the
     * unit of work ended (then it is dropped with a warning); a rolled-back one is dropped.
     *
     * @param bool $failed The unit of work ended with an exception: every waiting change is marked as failed (sticky)
     * @param bool $endOfUnit The unit of work is over (false only for `kernel.exception`)
     *
     * @return list<MonitorChangedEvent>
     */
    public function takeReady(bool $failed, bool $endOfUnit = true): array
    {
        $keep    = [];
        $dropped = 0;
        foreach ($this->deferred as $entry) {
            $entry['failed'] = $entry['failed'] || $failed;
            if ($entry['connection']->isTransactionActive()) {
                if ($endOfUnit) {
                    ++$dropped;
                } else {
                    $keep[] = $entry;
                }
                continue;
            }
            if ($this->committed($entry)) {
                // @igor-ignore - Per-request outbox, emptied by kernel.reset, at the end of every unit and before every replay.
                $this->ready = [...$this->ready, ...$entry['events']];
            }
        }
        // @igor-ignore - Per-request outbox, emptied by kernel.reset, at the end of every unit and before every replay.
        $this->deferred = $keep;
        $this->warnDropped($dropped);
        $ready = $this->ready;
        // @igor-ignore - Per-request outbox, emptied by kernel.reset, at the end of every unit and before every replay.
        $this->ready = [];

        return $ready;
    }

    /**
     * Before a write unit replays its work: the waiting changes that are provably committed (an earlier unit of the
     * request: history rows found, or a commit counted by the ledger since their defer) move to the ready list; the
     * others are dropped (the replay records them again). Without the history and without a ledger nothing can tell
     * an earlier unit's commit from the failed attempt, so everything is dropped (no duplicate, no phantom).
     */
    public function resetBetweenAttempts(): void
    {
        foreach ($this->deferred as $entry) {
            $provable = $entry['confirm'] instanceof Closure || $this->ledger instanceof CommitLedger;
            if ($provable && !$entry['connection']->isTransactionActive() && $this->committed($entry)) {
                // @igor-ignore - Per-request outbox, emptied by kernel.reset, at the end of every unit and before every replay.
                $this->ready = [...$this->ready, ...$entry['events']];
            }
        }
        // @igor-ignore - Per-request outbox, emptied by kernel.reset, at the end of every unit and before every replay.
        $this->deferred = [];
    }

    /**
     * Drops everything left between requests, with a warning.
     */
    public function reset(): void
    {
        $this->warnDropped(count($this->deferred) + count($this->ready));
        $this->deferred = [];
        $this->ready    = [];
    }

    /**
     * Whether the transaction around a deferred change committed (see the class comment).
     *
     * @param array{events: list<MonitorChangedEvent>, connection: Connection, count: int|null, confirm: (Closure(): bool)|null, failed: bool} $entry
     */
    private function committed(array $entry): bool
    {
        if ($entry['confirm'] instanceof Closure) {
            return ($entry['confirm'])();
        }

        return !$entry['failed'] && (!$this->ledger instanceof CommitLedger || $this->ledger->count() !== $entry['count']);
    }

    private function warnDropped(int $count): void
    {
        if ($count > 0) {
            $this->logger->warning('Uptime monitor: {count} monitor change event(s) were dropped: the transaction around them never ended.', [
                'bundle' => 'NowoUptimeMonitorBundle',
                'count'  => $count,
            ]);
        }
    }
}
