<?php

declare(strict_types=1);

namespace Nowo\UptimeMonitorBundle\Tests\Database;

use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Nowo\TransactionContract\TransientStorageFailure;
use Nowo\TransactionDoctrine\TransientFailureClassifier;
use Nowo\TransactionDoctrine\TransientFailureKind;
use Nowo\UptimeMonitorBundle\Entity\CheckAggregate;
use Nowo\UptimeMonitorBundle\Entity\Monitor;
use Nowo\UptimeMonitorBundle\Entity\Tenant;
use Nowo\UptimeMonitorBundle\Enum\AggregatePeriod;
use Nowo\UptimeMonitorBundle\Enum\MonitorType;
use Nowo\UptimeMonitorBundle\Limits\ConfigurableTenantLimits;
use Nowo\UptimeMonitorBundle\Limits\TenantQuota;
use Nowo\UptimeMonitorBundle\Repository\MonitorRepository;
use Nowo\UptimeMonitorBundle\Service\CheckExecutorService;
use Nowo\UptimeMonitorBundle\Service\DueChecksRunner;
use Nowo\UptimeMonitorBundle\Service\TenantProvisioner;
use Nowo\UptimeMonitorBundle\Tests\Database\Support\DatabaseSupport;
use Nowo\UptimeMonitorBundle\Transaction\UptimeTransactionRunner;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use RuntimeException;

use function array_merge;
use function count;
use function fclose;
use function fgets;
use function is_array;
use function is_resource;
use function json_decode;
use function microtime;
use function proc_close;
use function proc_open;
use function sprintf;
use function stream_get_contents;
use function usleep;

use const PHP_BINARY;

/**
 * 1.29 (Beacon spec 239): lock waits, deadlocks and concurrent processes on a real server (MySQL / MariaDB,
 * PostgreSQL). Skipped without `UPTIME_TEST_DATABASE_URL`; the CI `Load readiness` job runs them with
 * `--fail-on-skipped` on MySQL 8.4 and PostgreSQL 16, with DBAL 4 and DBAL 3.
 *
 * On 1.28.3 the same scenarios answer a raw lock wait timeout, a raw `1305 SAVEPOINT … does not exist` (MySQL) or
 * deadlock (PostgreSQL) with the connection left nested, count checks twice, lose aggregate counts and fail tenant
 * provisioning with unique violations.
 */
#[Group('database')]
#[CoversClass(UptimeTransactionRunner::class)]
#[CoversClass(TenantQuota::class)]
#[CoversClass(CheckExecutorService::class)]
#[CoversClass(DueChecksRunner::class)]
#[CoversClass(TenantProvisioner::class)]
#[CoversClass(MonitorRepository::class)]
final class LoadReadinessTest extends TestCase
{
    private string $url;
    private EntityManagerInterface $em;
    private Tenant $tenant;

    protected function setUp(): void
    {
        $url = DatabaseSupport::url();
        if ($url === null) {
            self::markTestSkipped(sprintf('Set %s to run the real-server tests.', DatabaseSupport::URL_ENV));
        }
        $this->url    = $url;
        $this->em     = DatabaseSupport::entityManager($url, true);
        $this->tenant = new Tenant('main', 'Main');
        $this->em->persist($this->tenant);
        $this->em->flush();
    }

    protected function tearDown(): void
    {
        if (isset($this->em)) {
            $this->em->getConnection()->close();
        }
    }

    /**
     * Another session holds the tenant row: the quota write gives up after the lock wait timeout with a transient
     * failure (503 on the bundle's routes), nothing written and no transaction left open. 1.28.3: a raw lock wait
     * timeout (500) and a closed entity manager.
     */
    public function testATenantLockWaitIsATransientFailureAndLeavesNoTransaction(): void
    {
        $peer = DatabaseSupport::connection($this->url);
        $peer->beginTransaction();
        $peer->fetchOne($this->forUpdate('SELECT id FROM uptime_tenant WHERE id = ?'), [$this->tenant->getId()]);
        DatabaseSupport::lockTimeout($this->em->getConnection(), 1);

        try {
            $this->quota()->withTenantLock($this->em, $this->tenant, function (): void {
                $this->em->persist($this->monitor('never'));
            });
            self::fail('the lock wait must fail');
        } catch (TransientStorageFailure $failure) {
            self::assertSame(1, $failure->retryAfterSeconds());
        } finally {
            $peer->rollBack();
            $peer->close();
        }

        self::assertSame(0, $this->em->getConnection()->getTransactionNestingLevel());
        self::assertSame(0, (int) $this->em->getConnection()->fetchOne("SELECT COUNT(*) FROM uptime_monitor WHERE name = 'never'"));
        $this->assertTheConnectionTakesTheNextUnit();
    }

    /**
     * The quota write deadlocks inside its flush (the nested level of the unit of work): a transient failure, and the
     * connection is usable at level 0 for the worker's next request. 1.28.3 on MySQL answered the ORM's second
     * rollback (`1305 SAVEPOINT DOCTRINE_2 does not exist`) and left the connection nested.
     */
    public function testADeadlockInsideTheQuotaFlushIsATransientFailureAndRepairsTheConnection(): void
    {
        $busy  = $this->monitor('busy');
        $other = $this->monitor('other');
        $this->em->persist($busy);
        $this->em->persist($other);
        $this->em->flush();
        // A hang would be a lock wait (and fail below), never a stuck test.
        DatabaseSupport::lockTimeout($this->em->getConnection(), 10);
        [$process, $pipes] = $this->start(['peer', $this->url, (string) $this->tenant->getId(), (string) $busy->getId(), (string) $other->getId()]);
        self::assertStringContainsString('ready', (string) fgets($pipes[1]));

        try {
            $this->quota()->withTenantLock($this->em, $this->tenant, static function () use ($busy, $pipes): void {
                // This unit holds the tenant row: let the peer queue on it, then write the row the peer holds.
                fwrite($pipes[0], "go\n");
                usleep(500_000);
                $busy->setName('mine');
            });
            self::fail('the deadlock must fail');
        } catch (TransientStorageFailure $failure) {
            self::assertSame(TransientFailureKind::Deadlock, (new TransientFailureClassifier())->classify($failure));
        } finally {
            $peer = $this->finish($process, $pipes);
        }

        self::assertSame(['peer' => 'locked'], $peer);
        self::assertSame(0, $this->em->getConnection()->getTransactionNestingLevel());
        $this->assertTheConnectionTakesTheNextUnit();
    }

    /**
     * Two processes check one monitor 40 times each at the same time (two schedulers, or a group refreshed by its
     * children): no failure, every check stored and counted once, at most one open incident. 1.28.3 lost counts in the
     * aggregate buckets, failed on the bucket's unique key and opened incidents twice.
     */
    public function testTwoProcessesCheckingOneMonitorStoreEveryCheckOnce(): void
    {
        $monitor = $this->monitor('busy');
        $this->em->persist($monitor);
        $this->em->flush();
        $start = sprintf('%.6F', microtime(true) + 1.5);
        $runs  = [];
        for ($p = 0; $p < 2; ++$p) {
            $runs[] = $this->start(['check', $this->url, (string) $monitor->getId(), '40', $start]);
        }
        $errors = [];
        foreach ($runs as [$process, $pipes]) {
            $out    = $this->finish($process, $pipes);
            $errors = array_merge($errors, is_array($out['errors'] ?? null) ? $out['errors'] : ['no output']);
        }

        self::assertSame([], $errors);
        $connection = $this->em->getConnection();
        self::assertSame(80, (int) $connection->fetchOne('SELECT COUNT(*) FROM uptime_check_result WHERE monitor_id = ?', [$monitor->getId()]));
        $this->em->clear();
        $buckets = $this->em->getRepository(CheckAggregate::class)->findBy(['period' => AggregatePeriod::Day]);
        $total   = 0;
        foreach ($buckets as $bucket) {
            $total += $bucket->getChecksTotal();
        }
        self::assertSame(80, $total, 'every check is counted once');
        self::assertLessThanOrEqual(1, (int) $connection->fetchOne('SELECT COUNT(*) FROM uptime_incident WHERE monitor_id = ? AND ended_at IS NULL', [$monitor->getId()]));
    }

    /**
     * Two schedulers scan 30 due monitors at the same time: each monitor is checked once. 1.28.3 checked most twice.
     */
    public function testTwoSchedulersCheckEveryDueMonitorOnce(): void
    {
        for ($i = 0; $i < 30; ++$i) {
            $this->em->persist($this->monitor('m-' . $i)->setNextCheckAt(new DateTimeImmutable('-1 minute')));
        }
        $this->em->flush();
        $start   = sprintf('%.6F', microtime(true) + 1.5);
        $runs    = [$this->start(['scheduler', $this->url, $start]), $this->start(['scheduler', $this->url, $start])];
        $checked = 0;
        foreach ($runs as [$process, $pipes]) {
            $out = $this->finish($process, $pipes);
            $checked += (int) ($out['checked'] ?? -1000);
        }

        self::assertSame(30, $checked);
        self::assertSame(30, (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM uptime_check_result'));
    }

    /**
     * Two processes provision the same 30 new tenants at the same time: both succeed and every tenant exists once.
     * 1.28.3 failed with unique violations.
     */
    public function testTwoProcessesProvisioningTheSameTenantsBothSucceed(): void
    {
        $start  = sprintf('%.6F', microtime(true) + 1.5);
        $runs   = [$this->start(['ensure', $this->url, '30', $start]), $this->start(['ensure', $this->url, '30', $start])];
        $errors = [];
        foreach ($runs as [$process, $pipes]) {
            $out    = $this->finish($process, $pipes);
            $errors = array_merge($errors, is_array($out['errors'] ?? null) ? $out['errors'] : ['no output']);
        }

        self::assertSame([], $errors);
        self::assertSame(30, (int) $this->em->getConnection()->fetchOne("SELECT COUNT(*) FROM uptime_tenant WHERE slug LIKE 't-%'"));
    }

    private function quota(): TenantQuota
    {
        return new TenantQuota(new ConfigurableTenantLimits(100, 30), new MonitorRepository(DatabaseSupport::registry($this->em)));
    }

    private function monitor(string $name): Monitor
    {
        return new Monitor($this->tenant, $name, MonitorType::Https, 'https://' . $name . '.test');
    }

    private function forUpdate(string $sql): string
    {
        return $sql . ' FOR UPDATE';
    }

    /**
     * The connection is at level 0 and a new unit of work commits on it.
     */
    private function assertTheConnectionTakesTheNextUnit(): void
    {
        $em = $this->em->isOpen() ? $this->em : DatabaseSupport::entityManager($this->url);
        $em->clear();
        $tenant = $em->find(Tenant::class, $this->tenant->getId());
        self::assertInstanceOf(Tenant::class, $tenant);
        (new UptimeTransactionRunner($em))->run(static function () use ($em, $tenant): void {
            $em->persist(new Monitor($tenant, 'after', MonitorType::Https, 'https://after.test'));
        });
        $connection = $em->getConnection();
        self::assertSame(0, $connection->getTransactionNestingLevel());
        self::assertSame(1, (int) $connection->fetchOne("SELECT COUNT(*) FROM uptime_monitor WHERE name = 'after'"));
    }

    /**
     * @param list<string> $args
     *
     * @return array{0: resource, 1: array<int, resource>}
     */
    private function start(array $args): array
    {
        // A test-only child process; the test waits for it to exit (each mode ends on its own).
        // @phpstan-ignore-next-line
        $process = proc_open([PHP_BINARY, __DIR__ . '/Support/worker.php', ...$args], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        if (!is_resource($process)) {
            throw new RuntimeException('The worker did not start.');
        }

        return [$process, $pipes];
    }

    /**
     * The worker's last JSON line.
     *
     * @param resource $process
     * @param array<int, resource> $pipes
     *
     * @return array<string, mixed>
     */
    private function finish($process, array $pipes): array
    {
        fclose($pipes[0]);
        $out = (string) stream_get_contents($pipes[1]);
        $err = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $code = proc_close($process);
        self::assertSame(0, $code, 'worker failed: ' . $err);
        $lines = array_values(array_filter(explode("\n", $out)));
        $last  = json_decode((string) end($lines), true);
        self::assertIsArray($last, 'worker output: ' . $out . $err);

        /* @var array<string, mixed> $last */
        return count($last) > 0 ? $last : [];
    }
}
