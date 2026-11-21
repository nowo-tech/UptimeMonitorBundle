<?php

declare(strict_types=1);

namespace Nowo\UptimeMonitorBundle\Tests\Integration\Service;

use Doctrine\DBAL\Configuration as DbalConfiguration;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use Nowo\TransactionContract\TransientStorageFailure;
use Nowo\TransactionDoctrine\ConflictCode;
use Nowo\UptimeMonitorBundle\Doctrine\UptimeEntityManager;
use Nowo\UptimeMonitorBundle\Doctrine\UptimeManagerRegistry;
use Nowo\UptimeMonitorBundle\Entity\Tenant;
use Nowo\UptimeMonitorBundle\Repository\TenantRepository;
use Nowo\UptimeMonitorBundle\Service\TenantProvisioner;
use Nowo\UptimeMonitorBundle\Tests\Database\Support\DatabaseSupport;
use Nowo\UptimeMonitorBundle\Tests\Support\BeforeStatementMiddleware;
use Nowo\UptimeMonitorBundle\Tests\Support\SingleManagerRegistry;
use Nowo\UptimeMonitorBundle\Tests\Support\SqliteEntityManagerFactory;
use Nowo\UptimeMonitorBundle\Transaction\UptimeTransactionRunner;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Throwable;

use function ucfirst;

/**
 * 1.36 (audit XZD-I2, REQ-RUNTIME-003 rule 6): the bulk tenant switch against a second connection that holds the tenant
 * rows. A lock wait is replayed by the runner and then surfaces as CONFLICT_001; nothing is half-written and the copies
 * the bundle's manager holds keep the committed value. Real row locks: MySQL / MariaDB / PostgreSQL only.
 */
#[Group('db')]
#[CoversClass(TenantProvisioner::class)]
final class TenantBulkSwitchConcurrencyDbTest extends TestCase
{
    private BeforeStatementMiddleware $race;
    private EntityManagerInterface $em;
    private UptimeEntityManager $private;
    private TenantRepository $tenants;
    private ?Connection $holder = null;

    protected function setUp(): void
    {
        if (!SqliteEntityManagerFactory::onServer()) {
            self::markTestSkipped('Row lock waits need MySQL / MariaDB or PostgreSQL.');
        }
        $this->race = new BeforeStatementMiddleware();
        $this->em   = SqliteEntityManagerFactory::create((new DbalConfiguration())->setMiddlewares([$this->race]));
        foreach (['alpha', 'beta', 'gamma'] as $slug) {
            $this->em->persist(new Tenant($slug, ucfirst($slug)));
        }
        $this->em->flush();
        $this->em->clear();
        $this->private = new UptimeEntityManager(new SingleManagerRegistry($this->em));
        $registry      = $this->createStub(ManagerRegistry::class);
        $registry->method('getManagerForClass')->willReturn($this->private);
        $this->tenants = new TenantRepository($registry);
        DatabaseSupport::lockTimeout($this->em->getConnection(), 1);
    }

    protected function tearDown(): void
    {
        if ($this->holder instanceof Connection) {
            try {
                $this->holder->rollBack();
            } catch (Throwable) {
            }
            $this->holder->close();
        }
        if (isset($this->em)) {
            $this->em->getConnection()->close();
        }
    }

    public function testALockHeldByAnotherWriterIsReplayedUntilItIsReleased(): void
    {
        $holder = $this->holdAlpha();
        // Attempt 1 waits and times out; before attempt 2 the other writer commits its own work.
        $this->race->before('UPDATE uptime_tenant', function () use ($holder): void {
            $this->race->before('UPDATE uptime_tenant', static function () use ($holder): void {
                $holder->commit();
            });
        });

        $changed = $this->provisioner()->setEnabledMany(['alpha', 'beta'], false);

        self::assertGreaterThanOrEqual(2, $changed);
        self::assertSame(['alpha' => 0, 'beta' => 0, 'gamma' => 1], $this->states());
        self::assertFalse($this->em->getConnection()->isTransactionActive());
    }

    public function testALockHeldThroughEveryAttemptIsARetrySafeConflictAndNothingIsWritten(): void
    {
        $held = $this->tenants->findOneBySlug('beta');
        self::assertInstanceOf(Tenant::class, $held);
        $this->holdAlpha();

        try {
            $this->provisioner()->setEnabledMany(['alpha', 'beta'], false);
            self::fail('lock conflict expected');
        } catch (TransientStorageFailure $conflict) {
            self::assertSame(ConflictCode::RetrySafe, ConflictCode::forFailure($conflict), 'CONFLICT_001');
        }

        $this->holder?->rollBack();
        self::assertSame(['alpha' => 1, 'beta' => 1, 'gamma' => 1], $this->states(), 'one statement: nothing half-written');
        self::assertTrue($held->isEnabled(), 'the managed copy keeps the committed value');
        self::assertSame(1, $this->provisioner()->setEnabledMany(['beta'], false), 'a retry once the lock is gone succeeds');
    }

    public function testInsideACallersTransactionTheConflictIsNotReplayed(): void
    {
        $this->holdAlpha();
        $connection = $this->em->getConnection();
        $statements = 0;
        $this->race->before('UPDATE uptime_tenant', function () use (&$statements): void {
            ++$statements;
            $this->race->before('UPDATE uptime_tenant', static function () use (&$statements): void {
                ++$statements;
            });
        });
        $connection->beginTransaction();

        try {
            $this->provisioner()->setEnabledMany(['alpha'], false);
            self::fail('lock conflict expected');
        } catch (TransientStorageFailure) {
            self::assertSame(1, $statements, 'one attempt: the caller decides');
        } finally {
            try {
                $connection->rollBack();
            } catch (Throwable) {
                $connection->close();
            }
        }
    }

    private function provisioner(): TenantProvisioner
    {
        $runner = new UptimeTransactionRunner($this->private, new UptimeManagerRegistry($this->createStub(ManagerRegistry::class), $this->private), UptimeEntityManager::NAME, 1, 0);

        return new TenantProvisioner($this->tenants, $this->private, $runner);
    }

    /**
     * Another connection locks alpha's row in a transaction it keeps open.
     */
    private function holdAlpha(): Connection
    {
        $this->holder = DriverManager::getConnection(SqliteEntityManagerFactory::params());
        $this->holder->beginTransaction();
        $this->holder->executeStatement("UPDATE uptime_tenant SET name = 'Alpha (held)' WHERE slug = 'alpha'");

        return $this->holder;
    }

    /**
     * @return array<string, int>
     */
    private function states(): array
    {
        $states = [];
        foreach ($this->em->getConnection()->fetchAllAssociative('SELECT slug, enabled FROM uptime_tenant ORDER BY slug') as $row) {
            $states[(string) $row['slug']] = (int) $row['enabled'];
        }

        return $states;
    }
}
