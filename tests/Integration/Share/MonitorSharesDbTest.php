<?php

declare(strict_types=1);

namespace Nowo\UptimeMonitorBundle\Tests\Integration\Share;

use Doctrine\DBAL\Configuration as DbalConfiguration;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Platforms\MariaDBPlatform;
use Doctrine\DBAL\Platforms\SQLitePlatform;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use Nowo\TransactionContract\TransientStorageFailure;
use Nowo\TransactionDoctrine\ConflictCode;
use Nowo\UptimeMonitorBundle\Doctrine\ExactUserIdentifierCollationListener;
use Nowo\UptimeMonitorBundle\Doctrine\UptimeEntityManager;
use Nowo\UptimeMonitorBundle\Doctrine\UptimeManagerRegistry;
use Nowo\UptimeMonitorBundle\Entity\Monitor;
use Nowo\UptimeMonitorBundle\Entity\Tenant;
use Nowo\UptimeMonitorBundle\Enum\MonitorType;
use Nowo\UptimeMonitorBundle\Share\DenyAllMonitorSharePolicy;
use Nowo\UptimeMonitorBundle\Share\Entity\MonitorShare;
use Nowo\UptimeMonitorBundle\Share\MonitorSharePolicyInterface;
use Nowo\UptimeMonitorBundle\Share\MonitorShareRefusedException;
use Nowo\UptimeMonitorBundle\Share\MonitorShares;
use Nowo\UptimeMonitorBundle\Share\MonitorShareView;
use Nowo\UptimeMonitorBundle\Tests\Database\Support\DatabaseSupport;
use Nowo\UptimeMonitorBundle\Tests\Support\BeforeStatementMiddleware;
use Nowo\UptimeMonitorBundle\Tests\Support\SingleManagerRegistry;
use Nowo\UptimeMonitorBundle\Tests\Support\SqliteEntityManagerFactory;
use Nowo\UptimeMonitorBundle\Transaction\UptimeTransactionRunner;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;
use Throwable;

use function array_map;
use function is_file;
use function str_repeat;
use function sys_get_temp_dir;
use function tempnam;
use function unlink;

/**
 * Bundle-owned monitor shares (1.35, Beacon spec 225) on a real schema: SQLite (a file, so a second connection sees
 * the same database) by default, MySQL / MariaDB / PostgreSQL with UPTIME_TEST_DATABASE_URL. Covers idempotent and
 * concurrent creates (REQ-RUNTIME-003 rule 4: unique key plus catch), lock conflicts (rule 6), exact identifiers (rule 5)
 * and the deny-by-default host policy.
 */
#[Group('db')]
#[CoversClass(MonitorShares::class)]
#[CoversClass(MonitorShare::class)]
#[CoversClass(MonitorShareView::class)]
#[CoversClass(MonitorShareRefusedException::class)]
#[CoversClass(DenyAllMonitorSharePolicy::class)]
#[CoversClass(ExactUserIdentifierCollationListener::class)]
final class MonitorSharesDbTest extends TestCase
{
    private const NOW = '2026-11-21 10:00:00';

    private ?string $file = null;
    private BeforeStatementMiddleware $race;
    private EntityManagerInterface $em;
    private UptimeEntityManager $private;
    private MonitorShares $shares;
    private int $monitorId;
    private int $otherId;

    protected function setUp(): void
    {
        $this->file = SqliteEntityManagerFactory::onServer() ? null : (string) tempnam(sys_get_temp_dir(), 'uptime-share-');
        $this->race = new BeforeStatementMiddleware();
        $this->em   = SqliteEntityManagerFactory::create((new DbalConfiguration())->setMiddlewares([$this->race]), withShares: true, sqliteFile: $this->file);
        $alpha      = new Tenant('alpha', 'Alpha');
        $beta       = new Tenant('beta', 'Beta');
        $monitor    = new Monitor($alpha, 'API', MonitorType::Https, 'https://api.test');
        $other      = new Monitor($beta, 'Other', MonitorType::Https, 'https://other.test');
        foreach ([$alpha, $beta, $monitor, $other] as $entity) {
            $this->em->persist($entity);
        }
        $this->em->flush();
        $this->monitorId = (int) $monitor->getId();
        $this->otherId   = (int) $other->getId();
        $this->em->clear();

        $this->private = new UptimeEntityManager(new SingleManagerRegistry($this->em));
        $this->shares  = $this->service(new class implements MonitorSharePolicyInterface {
            public function canShareWith(string $tenantSlug, int $monitorId, string $userIdentifier): bool
            {
                return $userIdentifier !== 'blocked';
            }
        });
    }

    protected function tearDown(): void
    {
        $this->em->getConnection()->close();
        if ($this->file !== null && is_file($this->file)) {
            unlink($this->file);
        }
    }

    public function testCreateIsIdempotentAndReadsBack(): void
    {
        $first  = $this->shares->create('alpha', $this->monitorId, 'user-1');
        $repeat = $this->shares->create('alpha', $this->monitorId, 'user-1');

        self::assertTrue($first->created);
        self::assertFalse($repeat->created);
        self::assertSame($first->id, $repeat->id);
        self::assertSame(['alpha', $this->monitorId, 'user-1', self::NOW], [$repeat->tenantSlug, $repeat->monitorId, $repeat->userIdentifier, $repeat->createdAt->format('Y-m-d H:i:s')]);
        self::assertSame(1, $this->rows());
        self::assertSame(0, $this->private->getUnitOfWork()->size(), 'nothing stays managed');

        $this->shares->create('alpha', $this->monitorId, 'user-0');
        self::assertSame(['user-0', 'user-1'], $this->shares->usersOf('alpha', $this->monitorId));
        self::assertSame([], $this->shares->usersOf('beta', $this->monitorId), 'another tenant reads nothing');
        self::assertSame([], $this->shares->usersOf('ALPHA', $this->monitorId));
        self::assertSame([], $this->shares->usersOf('alpha', 0));
        $mine = $this->shares->sharedWith('user-1');
        self::assertCount(1, $mine);
        self::assertSame(['alpha', $this->monitorId], [$mine[0]->monitorRef()->tenantSlug, $mine[0]->monitorRef()->monitorId]);
        self::assertTrue($this->shares->isSharedWith('alpha', $this->monitorId, 'user-1'));
        self::assertFalse($this->shares->isSharedWith('beta', $this->monitorId, 'user-1'));
        self::assertFalse($this->shares->isSharedWith('alpha', $this->monitorId, ''));
    }

    public function testIdentifiersCompareExactly(): void
    {
        $this->shares->create('alpha', $this->monitorId, 'UserA');

        $lower = $this->shares->create('alpha', $this->monitorId, 'usera');
        $space = $this->shares->create('alpha', $this->monitorId, 'usera ');

        self::assertTrue($lower->created, 'usera is another user');
        self::assertTrue($space->created, 'a trailing space is another user');
        self::assertSame(['UserA', 'usera', 'usera '], $this->shares->usersOf('alpha', $this->monitorId));
        self::assertSame(['usera'], array_map(static fn (MonitorShareView $share): string => $share->userIdentifier, $this->shares->sharedWith('usera')));
        self::assertFalse($this->shares->isSharedWith('alpha', $this->monitorId, 'USERA'));
        self::assertTrue($this->shares->delete('alpha', $this->monitorId, 'usera'));
        self::assertSame(['UserA', 'usera '], $this->shares->usersOf('alpha', $this->monitorId));
    }

    public function testTheUserIdentifierColumnIsBinaryNoPadOnMysqlAndMariadb(): void
    {
        $connection = $this->em->getConnection();
        $platform   = $connection->getDatabasePlatform();
        if (!$platform instanceof AbstractMySQLPlatform) {
            self::assertNull(ExactUserIdentifierCollationListener::collationFor($platform));

            return;
        }

        $collation = $connection->fetchOne("SELECT COLLATION_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'uptime_monitor_share' AND COLUMN_NAME = 'user_identifier'");
        self::assertSame($platform instanceof MariaDBPlatform ? 'utf8mb4_nopad_bin' : 'utf8mb4_0900_bin', $collation);
    }

    public function testRefusalsWriteNothing(): void
    {
        foreach ([
            ['alpha', $this->monitorId, '', MonitorShareRefusedException::INVALID_USER],
            ['alpha', $this->monitorId, str_repeat('x', 129), MonitorShareRefusedException::INVALID_USER],
            ['alpha', $this->monitorId, "user\n2", MonitorShareRefusedException::INVALID_USER],
            ['alpha', $this->monitorId, "\xff", MonitorShareRefusedException::INVALID_USER],
            ['alpha', $this->otherId, 'user-1', MonitorShareRefusedException::MONITOR_NOT_FOUND],
            ['Alpha', $this->monitorId, 'user-1', MonitorShareRefusedException::MONITOR_NOT_FOUND],
            ['alpha', $this->monitorId + 1000, 'user-1', MonitorShareRefusedException::MONITOR_NOT_FOUND],
            ['alpha', $this->monitorId, 'blocked', MonitorShareRefusedException::DENIED],
        ] as [$slug, $id, $user, $reason]) {
            try {
                $this->shares->create($slug, $id, $user);
                self::fail('refused: ' . $reason);
            } catch (MonitorShareRefusedException $refused) {
                self::assertSame($reason, $refused->reason);
                if ($user !== '') {
                    self::assertStringNotContainsString($user, $refused->getMessage());
                }
            }
        }
        self::assertSame(0, $this->rows());
        self::assertTrue(MonitorShares::isValidUser(str_repeat('é', 128)), '128 characters, not bytes');
        self::assertSame('The share was refused.', (new MonitorShareRefusedException('other'))->getMessage());
    }

    public function testWithoutAHostPolicyEveryShareIsDenied(): void
    {
        $this->expectExceptionObject(new MonitorShareRefusedException(MonitorShareRefusedException::DENIED));

        (new MonitorShares($this->private))->create('alpha', $this->monitorId, 'user-1');
    }

    public function testTheLoserOfAConcurrentDoubleSubmitGetsTheWinnersShare(): void
    {
        $this->race->before('INSERT INTO uptime_monitor_share', function (): void {
            $this->otherConnection()->insert('uptime_monitor_share', ['monitor_id' => $this->monitorId, 'user_identifier' => 'user-1', 'created_at' => '2026-11-21 09:59:59']);
        });

        $share = $this->shares->create('alpha', $this->monitorId, 'user-1');

        self::assertFalse($share->created, 'the unique violation is never a 500: the existing share comes back');
        self::assertSame('2026-11-21 09:59:59', $share->createdAt->format('Y-m-d H:i:s'));
        self::assertSame(1, $this->rows());
        self::assertTrue($this->shares->create('alpha', $this->monitorId, 'user-2')->created, 'the connection and the manager are still usable');
    }

    public function testTheLoserInsideAHostTransactionStillSeesTheWinner(): void
    {
        if (!SqliteEntityManagerFactory::onServer()) {
            self::markTestSkipped('SQLite holds a database lock for the open transaction: the second connection cannot write.');
        }
        $connection = $this->em->getConnection();
        $connection->beginTransaction();
        // A read first: MySQL / MariaDB REPEATABLE READ takes the snapshot here.
        self::assertSame(0, $this->rows());
        $this->race->before('INSERT INTO uptime_monitor_share', function (): void {
            $this->otherConnection()->insert('uptime_monitor_share', ['monitor_id' => $this->monitorId, 'user_identifier' => 'user-1', 'created_at' => '2026-11-21 09:59:59']);
        });

        $share = $this->shares->create('alpha', $this->monitorId, 'user-1');

        self::assertFalse($share->created);
        self::assertTrue($connection->isTransactionActive(), 'the host transaction is left open (savepoint rolled back only)');
        self::assertSame(1, $connection->getTransactionNestingLevel());
        $connection->commit();
        self::assertSame(1, $this->rows());
    }

    public function testAWinnerGoneAgainIsARetrySafeConflict(): void
    {
        $this->race->before('INSERT INTO uptime_monitor_share', function (): void {
            $this->otherConnection()->insert('uptime_monitor_share', ['monitor_id' => $this->monitorId, 'user_identifier' => 'user-1', 'created_at' => '2026-11-21 09:59:59']);
            $this->race->before('FROM uptime_monitor_share', function (): void {
                $this->otherConnection()->executeStatement('DELETE FROM uptime_monitor_share');
            });
        });

        try {
            $this->shares->create('alpha', $this->monitorId, 'user-1');
            self::fail('conflict expected');
        } catch (TransientStorageFailure $conflict) {
            self::assertSame(ConflictCode::RetrySafe, ConflictCode::forFailure($conflict));
            self::assertSame(1, $conflict->retryAfterSeconds());
        }
        self::assertSame(0, $this->rows());
        self::assertTrue($this->shares->create('alpha', $this->monitorId, 'user-1')->created, 'a retry succeeds');
    }

    public function testAMonitorDeletedMeanwhileIsNotFound(): void
    {
        $this->race->before('INSERT INTO uptime_monitor_share', function (): void {
            $this->otherConnection()->executeStatement('DELETE FROM uptime_monitor WHERE id = ?', [$this->monitorId]);
        });

        try {
            $this->shares->create('alpha', $this->monitorId, 'user-1');
            self::fail('refused');
        } catch (MonitorShareRefusedException $refused) {
            self::assertSame(MonitorShareRefusedException::MONITOR_NOT_FOUND, $refused->reason);
        }
        self::assertSame(0, $this->rows());
    }

    public function testIdentifiersThatCollideOnANonBinaryColumnAreRefused(): void
    {
        $connection = $this->em->getConnection();
        $platform   = $connection->getDatabasePlatform();
        if ($platform instanceof AbstractMySQLPlatform) {
            $connection->executeStatement('ALTER TABLE uptime_monitor_share MODIFY user_identifier VARCHAR(128) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL');
        } elseif ($platform instanceof SQLitePlatform) {
            $connection->executeStatement('DROP TABLE uptime_monitor_share');
            $connection->executeStatement('CREATE TABLE uptime_monitor_share (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, monitor_id INTEGER NOT NULL REFERENCES uptime_monitor (id) ON DELETE CASCADE, user_identifier VARCHAR(128) NOT NULL COLLATE NOCASE, created_at DATETIME NOT NULL)');
            $connection->executeStatement('CREATE UNIQUE INDEX uniq_uptime_monitor_share_pair ON uptime_monitor_share (monitor_id, user_identifier)');
        } else {
            self::markTestSkipped('PostgreSQL compares exactly: no look-alike can collide.');
        }
        $this->shares->create('alpha', $this->monitorId, 'UserA');

        try {
            $this->shares->create('alpha', $this->monitorId, 'usera');
            self::fail('refused');
        } catch (MonitorShareRefusedException $refused) {
            self::assertSame(MonitorShareRefusedException::IDENTIFIER_COLLISION, $refused->reason, 'a clear refusal, never a 409 a retry cannot clear');
        }
        self::assertSame(1, $this->rows());
        self::assertSame(['UserA'], $this->shares->usersOf('alpha', $this->monitorId));
        self::assertFalse($this->shares->isSharedWith('alpha', $this->monitorId, 'usera'), 'the PHP re-check keeps reads exact');
    }

    public function testALockWaitOnTheWinnersRowIsATransientConflict(): void
    {
        if (!SqliteEntityManagerFactory::onServer()) {
            self::markTestSkipped('Row lock waits need MySQL / MariaDB or PostgreSQL.');
        }
        $holder = $this->otherConnection();
        $this->race->before('INSERT INTO uptime_monitor_share', function () use ($holder): void {
            $holder->insert('uptime_monitor_share', ['monitor_id' => $this->monitorId, 'user_identifier' => 'user-1', 'created_at' => '2026-11-21 09:59:59']);
            $this->race->before('FROM uptime_monitor_share', static function () use ($holder): void {
                // Another writer holds the winner's row while the loser reads it.
                $holder->beginTransaction();
                $holder->fetchOne('SELECT id FROM uptime_monitor_share FOR UPDATE');
            });
        });
        DatabaseSupport::lockTimeout($this->em->getConnection(), 1);

        try {
            $this->service(null, $this->noBackoffRunner())->create('alpha', $this->monitorId, 'user-1');
            self::fail('lock conflict expected');
        } catch (TransientStorageFailure $conflict) {
            self::assertSame(ConflictCode::RetrySafe, ConflictCode::forFailure($conflict), 'CONFLICT_001');
        } finally {
            try {
                $holder->rollBack();
            } catch (Throwable) {
                $holder->close();
            }
        }

        self::assertFalse($this->em->getConnection()->isTransactionActive());
        self::assertFalse($this->shares->create('alpha', $this->monitorId, 'user-1')->created, 'the winner\'s share afterwards');
    }

    public function testALockWaitIsATransientConflictAndNothingIsWritten(): void
    {
        if (!SqliteEntityManagerFactory::onServer()) {
            self::markTestSkipped('Row lock waits need MySQL / MariaDB or PostgreSQL.');
        }
        $holder = $this->otherConnection();
        $holder->beginTransaction();
        $holder->insert('uptime_monitor_share', ['monitor_id' => $this->monitorId, 'user_identifier' => 'user-1', 'created_at' => '2026-11-21 09:59:59']);
        DatabaseSupport::lockTimeout($this->em->getConnection(), 1);

        try {
            $this->service(null, $this->noBackoffRunner())->create('alpha', $this->monitorId, 'user-1');
            self::fail('lock conflict expected');
        } catch (TransientStorageFailure $conflict) {
            self::assertSame(ConflictCode::RetrySafe, ConflictCode::forFailure($conflict), 'CONFLICT_001: nothing committed');
        } finally {
            try {
                $holder->rollBack();
            } catch (Throwable) {
                $holder->close();
            }
        }

        self::assertSame(0, $this->rows());
        self::assertFalse($this->em->getConnection()->isTransactionActive(), 'no transaction left open');
        self::assertTrue($this->shares->create('alpha', $this->monitorId, 'user-1')->created, 'usable afterwards');
    }

    public function testDeleteIsIdempotentAndTenantSafe(): void
    {
        $this->shares->create('alpha', $this->monitorId, 'user-1');

        self::assertFalse($this->shares->delete('beta', $this->monitorId, 'user-1'));
        self::assertFalse($this->shares->delete('alpha', $this->monitorId, ''));
        self::assertTrue($this->shares->delete('alpha', $this->monitorId, 'user-1'));
        self::assertFalse($this->shares->delete('alpha', $this->monitorId, 'user-1'));
        self::assertSame(0, $this->rows());
    }

    public function testErasingAUserRemovesAllTheirShares(): void
    {
        $this->shares->create('alpha', $this->monitorId, 'user-1');
        $this->shares->create('alpha', $this->monitorId, 'user-2');

        self::assertSame(1, $this->shares->deleteAllForUser('user-1'));
        self::assertSame(0, $this->shares->deleteAllForUser('user-1'));
        self::assertSame(0, $this->shares->deleteAllForUser(''));
        self::assertSame([], $this->shares->sharedWith(''));
        self::assertSame(['user-2'], $this->shares->usersOf('alpha', $this->monitorId));
    }

    public function testSharesGoWithTheirMonitor(): void
    {
        $this->shares->create('alpha', $this->monitorId, 'user-1');

        $this->em->getConnection()->executeStatement('DELETE FROM uptime_monitor WHERE id = ?', [$this->monitorId]);

        self::assertSame(0, $this->rows());
    }

    private function service(?MonitorSharePolicyInterface $policy, ?UptimeTransactionRunner $runner = null): MonitorShares
    {
        return new MonitorShares(
            $this->private,
            $policy ?? new class implements MonitorSharePolicyInterface {
                public function canShareWith(string $tenantSlug, int $monitorId, string $userIdentifier): bool
                {
                    return true;
                }
            },
            new MockClock(self::NOW),
            $runner ?? new UptimeTransactionRunner($this->private, new UptimeManagerRegistry($this->createStub(ManagerRegistry::class), $this->private), UptimeEntityManager::NAME, 1, 0),
        );
    }

    private function noBackoffRunner(): UptimeTransactionRunner
    {
        return new UptimeTransactionRunner($this->private, new UptimeManagerRegistry($this->createStub(ManagerRegistry::class), $this->private), UptimeEntityManager::NAME, 1, 0);
    }

    private function otherConnection(): Connection
    {
        return DriverManager::getConnection(SqliteEntityManagerFactory::params($this->file));
    }

    private function rows(): int
    {
        return (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM uptime_monitor_share');
    }
}
