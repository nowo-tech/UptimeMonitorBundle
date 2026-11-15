<?php

declare(strict_types=1);

namespace Nowo\UptimeMonitorBundle\Transaction;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use Nowo\TransactionContract\TransactionRunnerInterface;
use Nowo\TransactionContract\TransientStorageFailure;
use Nowo\TransactionDoctrine\DoctrineTransactionRunner;
use Nowo\UptimeMonitorBundle\Doctrine\UptimeEntityManager;
use Nowo\UptimeMonitorBundle\Doctrine\UptimeManagerRegistry;

use function max;
use function min;
use function random_int;
use function usleep;

/**
 * 1.29 (Beacon spec 239): the runner of every bundle write, registered as `nowo_uptime_monitor.transaction_runner`.
 *
 * The bundle never runs its writes through a runner the application binds to nowo-tech/transaction-contract's
 * {@see TransactionRunnerInterface}: such a runner drives the application's entity manager. This one drives the
 * bundle's private manager ({@see UptimeEntityManager}) through nowo-tech/transaction-doctrine's ORM runner:
 *
 * - one transaction per unit of work (a savepoint inside a transaction the application opened), the private manager
 *   flushed inside it, committed by the runner: the bundle never flushes inside a transaction it did not open;
 * - after any failure no transaction of its own stays open: the levels it opened are rolled back, and the connection
 *   is closed only when that rollback fails (a MySQL / MariaDB deadlock inside a nested flush drops the savepoints, so
 *   `ROLLBACK TO SAVEPOINT` fails with 1305). The worker's next request never inherits a nested connection;
 * - after a storage failure the private manager is closed and replaced through the bundle registry; after a refusal
 *   only what the unit loaded is forgotten. An application manager is never flushed, cleared, closed or reset;
 * - a transient failure (deadlock, lock wait timeout, serialization failure, lost savepoint) surfaces as
 *   {@see TransientStorageFailure} with a Retry-After hint: 503 on the bundle's routes.
 *
 * {@see self::run()} makes one attempt (HTTP writes: the client retries after the 503). {@see self::runRetrying()}
 * replays a transient failure a few times with full-jitter backoff, only when it is the outermost transaction (inside a
 * transaction of the application the storage may have rolled back the caller's work too): background units of work
 * (scheduled checks, push ingest, the monitors file, the rollup), which must therefore be replayable.
 *
 * Stateless: one instance serves every request of a long-running worker.
 */
final readonly class UptimeTransactionRunner implements TransactionRunnerInterface
{
    /** The service id the bundle services are wired to. */
    public const SERVICE_ID = 'nowo_uptime_monitor.transaction_runner';

    /** Attempts of {@see self::runRetrying()} (the first one included). */
    public const RETRY_ATTEMPTS = 3;

    /** Upper bound of the first backoff, in milliseconds; it doubles per attempt (full jitter: 0 to the bound). */
    public const BACKOFF_BASE_MILLISECONDS = 50;

    /** Upper bound of any backoff, in milliseconds. */
    public const BACKOFF_CAP_MILLISECONDS = 1000;

    /**
     * @param EntityManagerInterface $entityManager The manager the bundle rows are written through (the private one)
     * @param ManagerRegistry|null $registry Replaces that manager after a storage failure (the bundle registry)
     * @param string|null $managerName The registry name of that manager
     * @param int|null $retryAfterSeconds Retry-After hint of the TransientStorageFailure; null for none
     * @param int $backoffBaseMilliseconds Upper bound of the first backoff of {@see self::runRetrying()} (0 = no wait)
     */
    public function __construct(
        private EntityManagerInterface $entityManager,
        private ?ManagerRegistry $registry = null,
        private ?string $managerName = null,
        private ?int $retryAfterSeconds = 1,
        private int $backoffBaseMilliseconds = self::BACKOFF_BASE_MILLISECONDS,
    ) {
    }

    /**
     * The runner of the bundle's private manager, replaced through the bundle registry after a storage failure.
     */
    public static function forBundle(UptimeEntityManager $entityManager, UptimeManagerRegistry $registry, ?int $retryAfterSeconds = 1): self
    {
        return new self($entityManager, $registry, UptimeEntityManager::NAME, $retryAfterSeconds);
    }

    /**
     * {@inheritDoc}
     */
    public function run(callable $operation): mixed
    {
        return DoctrineTransactionRunner::forEntityManager($this->entityManager, $this->registry, $this->managerName, $this->retryAfterSeconds)->run($operation);
    }

    /**
     * Same as {@see self::run()}, but a transient failure of the OUTERMOST transaction is replayed up to `$attempts`
     * times in all, after a full-jitter backoff. Inside a transaction the caller opened it is not replayed: the storage
     * may have rolled back the caller's work as well (a MySQL deadlock rolls back the whole transaction), so the failure
     * goes to the caller. The operation must be replayable: it loads what it changes, and its side effects (events,
     * notifications) wait until this method returned.
     *
     * @template T
     *
     * @param callable(): T $operation The unit of work; replayable
     * @param int $attempts Attempts in all (at least one)
     *
     * @throws TransientStorageFailure When the last attempt failed transiently, or a nested one did
     *
     * @return T What the operation returned
     */
    public function runRetrying(callable $operation, int $attempts = self::RETRY_ATTEMPTS): mixed
    {
        $attempts = max(1, $attempts);
        for ($attempt = 1;; ++$attempt) {
            $outermost = !$this->entityManager->getConnection()->isTransactionActive();
            try {
                return $this->run($operation);
            } catch (TransientStorageFailure $failure) {
                if (!$outermost || $attempt >= $attempts) {
                    throw $failure;
                }
                $this->backoff($attempt);
            }
        }
    }

    /**
     * Sleeps a random time between 0 and the attempt's bound (full jitter: concurrent losers do not retry in lockstep).
     */
    private function backoff(int $attempt): void
    {
        $bound = min(self::BACKOFF_CAP_MILLISECONDS, $this->backoffBaseMilliseconds << ($attempt - 1));
        if ($bound > 0) {
            usleep(random_int(0, $bound) * 1000);
        }
    }
}
