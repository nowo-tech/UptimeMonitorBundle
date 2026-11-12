<?php

declare(strict_types=1);

namespace Nowo\UptimeMonitorBundle\Tests\Unit\Doctrine;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Platforms\AbstractPlatform;
use Nowo\UptimeMonitorBundle\Doctrine\UptimeSavepoint;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * 1.28.0: bulk bundle statements run in a savepoint inside a transaction of the application.
 */
#[CoversClass(UptimeSavepoint::class)]
final class UptimeSavepointTest extends TestCase
{
    private Connection $connection;

    private bool $failNext = false;

    private ?RuntimeException $toThrow = null;

    protected function setUp(): void
    {
        $this->connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $this->connection->executeStatement('CREATE TABLE t (id INTEGER PRIMARY KEY, v TEXT)');
    }

    public function testOutsideATransactionTheStatementRunsAsBefore(): void
    {
        self::assertSame(1, UptimeSavepoint::run($this->connection, fn (): int => (int) $this->connection->executeStatement("INSERT INTO t (v) VALUES ('a')")));
        self::assertSame(1, $this->rows());
    }

    public function testAFailingStatementUndoesOnlyItselfInsideTheApplicationTransaction(): void
    {
        $this->connection->beginTransaction();
        $this->connection->executeStatement("INSERT INTO t (v) VALUES ('host')");

        $this->failNext = true;
        try {
            UptimeSavepoint::run($this->connection, $this->bundleInsert(...));
        } catch (RuntimeException $exception) {
            $this->failNext = false;
            self::assertSame('bundle statement failed', $exception->getMessage());
        }
        self::assertFalse($this->failNext, 'the exception is rethrown');

        self::assertSame(1, UptimeSavepoint::run($this->connection, $this->bundleInsert(...)), 'the connection is still usable');
        $this->connection->executeStatement("DELETE FROM t WHERE v = 'bundle'");
        self::assertTrue($this->connection->isTransactionActive());
        $this->connection->commit();
        self::assertSame(['host'], $this->connection->fetchFirstColumn('SELECT v FROM t'));
    }

    /**
     * 1.28.1 (audit NX U2): when the savepoint rollback fails too, the statement's own exception reaches the caller.
     */
    public function testAFailingSavepointRollbackNeverHidesTheStatementFailure(): void
    {
        $platform = $this->createStub(AbstractPlatform::class);
        $platform->method('supportsSavepoints')->willReturn(true);
        $connection = $this->createMock(Connection::class);
        $connection->method('isTransactionActive')->willReturn(true);
        $connection->method('getDatabasePlatform')->willReturn($platform);
        $connection->method('getTransactionNestingLevel')->willReturn(1);
        $connection->expects(self::once())->method('createSavepoint')->with('nowo_uptime_1');
        $connection->expects(self::once())->method('rollbackSavepoint')->with('nowo_uptime_1')
            ->willThrowException(new RuntimeException('savepoint rollback failed'));
        $connection->expects(self::never())->method('releaseSavepoint');

        $original      = new RuntimeException('bundle statement failed');
        $this->toThrow = $original;
        $caught        = null;
        try {
            UptimeSavepoint::run($connection, $this->statement(...));
        } catch (RuntimeException $exception) {
            $caught = $exception;
        }
        self::assertSame($original, $caught, 'the statement failure is rethrown, not the rollback error');
    }

    private function statement(): int
    {
        if ($this->toThrow instanceof RuntimeException) {
            throw $this->toThrow;
        }

        return 1;
    }

    private function bundleInsert(): int
    {
        $inserted = (int) $this->connection->executeStatement("INSERT INTO t (v) VALUES ('bundle')");
        if ($this->failNext) {
            throw new RuntimeException('bundle statement failed');
        }

        return $inserted;
    }

    private function rows(): int
    {
        return (int) $this->connection->fetchOne('SELECT COUNT(*) FROM t');
    }
}
