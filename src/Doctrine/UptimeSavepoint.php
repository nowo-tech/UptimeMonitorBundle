<?php

declare(strict_types=1);

namespace Nowo\UptimeMonitorBundle\Doctrine;

use Doctrine\DBAL\Connection;
use Throwable;

/**
 * Runs one bundle statement that bypasses the unit of work (bulk DQL) inside a savepoint when the shared connection is
 * already in a transaction (1.28.0, REQ-ARCH-001 rule 6).
 *
 * The private manager shares the application's connection, so a transaction the application opened around a bundle
 * call includes the bundle statements. On PostgreSQL a failing statement aborts the whole transaction ("current
 * transaction is aborted"); rolling back to the savepoint undoes only the bundle statement and leaves the application's
 * transaction usable. The exception is rethrown unchanged, also when the savepoint rollback itself fails (1.28.1). Outside a transaction (autocommit), or on a platform without
 * savepoints, the statement runs as before.
 *
 * @internal
 */
final class UptimeSavepoint
{
    /** Savepoint name prefix; the transaction nesting level makes it unique (the statement never nests itself). */
    private const PREFIX = 'nowo_uptime_';

    /**
     * @template T
     *
     * @param callable(): T $statement
     *
     * @return T
     */
    public static function run(Connection $connection, callable $statement): mixed
    {
        if (!$connection->isTransactionActive() || !$connection->getDatabasePlatform()->supportsSavepoints()) {
            return $statement();
        }

        $name = self::PREFIX . $connection->getTransactionNestingLevel();
        $connection->createSavepoint($name);
        try {
            $result = $statement();
        } catch (Throwable $exception) {
            try {
                $connection->rollbackSavepoint($name);
            } catch (Throwable) {
                // The statement's own failure is what the caller must see (1.28.1, audit NX U2): a savepoint rollback
                // that fails too (connection lost, transaction already aborted) never hides it.
            }

            throw $exception;
        }
        $connection->releaseSavepoint($name);

        return $result;
    }
}
