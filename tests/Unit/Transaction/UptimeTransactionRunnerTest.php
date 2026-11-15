<?php

declare(strict_types=1);

namespace Nowo\UptimeMonitorBundle\Tests\Unit\Transaction;

use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use Nowo\TransactionContract\TransientStorageFailure;
use Nowo\UptimeMonitorBundle\Doctrine\UptimeEntityManager;
use Nowo\UptimeMonitorBundle\Doctrine\UptimeManagerRegistry;
use Nowo\UptimeMonitorBundle\Entity\Tenant;
use Nowo\UptimeMonitorBundle\Tests\Support\SingleManagerRegistry;
use Nowo\UptimeMonitorBundle\Tests\Support\SqliteEntityManagerFactory;
use Nowo\UptimeMonitorBundle\Transaction\UptimeTransactionRunner;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Throwable;

#[CoversClass(UptimeTransactionRunner::class)]
final class UptimeTransactionRunnerTest extends TestCase
{
    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        $this->em = SqliteEntityManagerFactory::create();
    }

    public function testRunFlushesAndCommitsTheUnit(): void
    {
        $level = (new UptimeTransactionRunner($this->em))->run(function (): int {
            $this->em->persist(new Tenant('main', 'Main'));

            return $this->em->getConnection()->getTransactionNestingLevel();
        });

        self::assertSame(1, $level, 'the unit runs in a transaction');
        self::assertSame(0, $this->em->getConnection()->getTransactionNestingLevel());
        self::assertSame(1, SqliteEntityManagerFactory::count($this->em, 'uptime_tenant'));
    }

    public function testATransientFailureOfTheOutermostUnitIsReplayed(): void
    {
        $attempts = 0;
        $result   = (new UptimeTransactionRunner($this->em, backoffBaseMilliseconds: 1))->runRetrying(function () use (&$attempts): int {
            if (++$attempts < 3) {
                throw new TransientStorageFailure(0);
            }
            $this->em->persist(new Tenant('main', 'Main'));

            return $attempts;
        });

        self::assertSame(3, $result);
        self::assertSame(1, SqliteEntityManagerFactory::count($this->em, 'uptime_tenant'));
    }

    public function testTheLastTransientFailureIsRethrown(): void
    {
        $attempts = 0;
        $failure  = $this->failureOf(static function () use (&$attempts): int {
            if (++$attempts < 100) {
                throw new TransientStorageFailure(0);
            }

            return $attempts;
        }, 2);

        self::assertInstanceOf(TransientStorageFailure::class, $failure);
        self::assertSame(2, $attempts);
    }

    public function testAUnitInsideATransactionOfTheCallerIsNeverReplayed(): void
    {
        $attempts   = 0;
        $connection = $this->em->getConnection();
        $connection->beginTransaction();

        $failure = $this->failureOf(static function () use (&$attempts): int {
            if (++$attempts < 100) {
                throw new TransientStorageFailure(0);
            }

            return $attempts;
        });

        self::assertInstanceOf(TransientStorageFailure::class, $failure);
        self::assertSame(1, $attempts);
        self::assertSame(1, $connection->getTransactionNestingLevel(), 'the caller\'s transaction is the caller\'s');
        $connection->commit();
    }

    public function testAnyOtherFailureIsNotReplayed(): void
    {
        $attempts = 0;
        $failure  = $this->failureOf(static function () use (&$attempts): int {
            if (++$attempts < 100) {
                throw new RuntimeException('refused');
            }

            return $attempts;
        });

        self::assertInstanceOf(RuntimeException::class, $failure);
        self::assertSame(1, $attempts);
    }

    public function testTheBundleRunnerReplacesThePrivateManagerAfterAStorageFailure(): void
    {
        $private = new UptimeEntityManager(new SingleManagerRegistry($this->em));
        $runner  = UptimeTransactionRunner::forBundle($private, new UptimeManagerRegistry($this->createStub(ManagerRegistry::class), $private));
        $private->persist(new Tenant('main', 'Main'));
        $private->flush();
        $first = $private->live();

        $failure = null;
        try {
            $runner->run(static function () use ($private): void {
                $private->persist(new Tenant('main', 'Duplicate'));
            });
        } catch (Throwable $caught) {
            $failure = $caught;
        }

        self::assertInstanceOf(UniqueConstraintViolationException::class, $failure, 'the unique slug refuses the row');
        self::assertNotSame($first, $private->live(), 'a fresh private manager');
        self::assertSame(0, $private->getConnection()->getTransactionNestingLevel());
    }

    /**
     * @param callable(): int $operation
     */
    private function failureOf(callable $operation, int $attempts = UptimeTransactionRunner::RETRY_ATTEMPTS): ?Throwable
    {
        try {
            (new UptimeTransactionRunner($this->em, backoffBaseMilliseconds: 0))->runRetrying($operation, $attempts);
        } catch (Throwable $failure) {
            return $failure;
        }

        return null;
    }
}
