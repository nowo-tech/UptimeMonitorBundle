<?php

declare(strict_types=1);

namespace Nowo\UptimeMonitorBundle\Audit;

use Doctrine\DBAL\Connection;
use Nowo\TransactionDoctrine\WriteUnit\CommitLedger;
use Nowo\TransactionDoctrine\WriteUnit\ResetBetweenAttemptsInterface;
use Nowo\UptimeMonitorBundle\Event\MonitorChangedEvent;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Contracts\Service\ResetInterface;

use function count;

/**
 * The monitor changes committed by a bundle run that sat inside a transaction the application opened (since 1.33): a
 * write unit around a bundle route or message, a host transaction around a bundle service. Their
 * {@see MonitorChangedEvent}s wait here until that transaction is over ({@see MonitorChangeOutboxListener} asks at the
 * end of every request, message and command), then go out once, or never when it was rolled back.
 *
 * Whether the application's transaction committed is read from nowo-tech/transaction-doctrine's {@see CommitLedger}
 * when NowoTransactionDoctrineBundle registers it (a commit since the change was deferred); without it, a unit that
 * ended with an exception drops them and any other unit dispatches them.
 *
 * Per-request state, emptied before a write unit replays its work ({@see ResetBetweenAttemptsInterface}: the replay
 * records the committed values again) and by `kernel.reset`; changes still waiting then are dropped with a warning.
 */
final class MonitorChangeOutbox implements ResetInterface, ResetBetweenAttemptsInterface
{
    /** @var list<array{0: list<MonitorChangedEvent>, 1: Connection, 2: int|null}> events, connection, ledger count */
    private array $deferred = [];

    /**
     * @param CommitLedger|null $ledger Counts the commits of the process (NowoTransactionDoctrineBundle)
     * @param LoggerInterface $logger Warns when waiting changes are dropped at a reset
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
     */
    public function defer(array $events, Connection $connection): void
    {
        // @igor-ignore - Per-request outbox, emptied by kernel.reset and before every write unit replay.
        $this->deferred[] = [$events, $connection, $this->ledger?->count()];
    }

    /**
     * The waiting changes whose transaction is over and committed; the ones still inside a transaction keep waiting,
     * the rolled-back ones are dropped.
     *
     * @param bool $failed Whether the unit of work ended with an exception (decides without a commit ledger)
     *
     * @return list<MonitorChangedEvent>
     */
    public function takeReady(bool $failed): array
    {
        $ready = [];
        $keep  = [];
        foreach ($this->deferred as $entry) {
            [$events, $connection, $count] = $entry;
            if ($connection->isTransactionActive()) {
                $keep[] = $entry;
                continue;
            }
            $committed = $this->ledger instanceof CommitLedger ? $this->ledger->count() !== $count : !$failed;
            if ($committed) {
                $ready = [...$ready, ...$events];
            }
        }
        // @igor-ignore - Per-request outbox, emptied by kernel.reset and before every write unit replay.
        $this->deferred = $keep;

        return $ready;
    }

    /**
     * Drops the waiting changes before a write unit replays its work (the replay records them again).
     */
    public function resetBetweenAttempts(): void
    {
        // @igor-ignore - Per-request outbox, emptied by kernel.reset and before every write unit replay.
        $this->deferred = [];
    }

    /**
     * Drops the waiting changes between requests (a transaction still open at the end of a request never committed).
     */
    public function reset(): void
    {
        if ($this->deferred !== []) {
            $this->logger->warning('Uptime monitor: {count} monitor change event(s) were dropped: the transaction around them never ended.', [
                'bundle' => 'NowoUptimeMonitorBundle',
                'count'  => count($this->deferred),
            ]);
        }
        $this->deferred = [];
    }
}
