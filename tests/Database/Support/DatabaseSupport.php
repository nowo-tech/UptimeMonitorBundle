<?php

declare(strict_types=1);

namespace Nowo\UptimeMonitorBundle\Tests\Database\Support;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Tools\DsnParser;
use Doctrine\ORM\Configuration;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Mapping\Driver\AttributeDriver;
use Doctrine\ORM\Mapping\UnderscoreNamingStrategy;
use Doctrine\ORM\Tools\SchemaTool;
use Doctrine\Persistence\ManagerRegistry;
use Nowo\UptimeMonitorBundle\Check\CheckRunnerInterface;
use Nowo\UptimeMonitorBundle\Doctrine\UptimeEntityManager;
use Nowo\UptimeMonitorBundle\Doctrine\UptimeManagerRegistry;
use Nowo\UptimeMonitorBundle\Entity\Monitor;
use Nowo\UptimeMonitorBundle\Enum\CheckStatus;
use Nowo\UptimeMonitorBundle\Model\CheckResultDto;
use Nowo\UptimeMonitorBundle\Repository\CheckAggregateRepository;
use Nowo\UptimeMonitorBundle\Repository\CheckResultRepository;
use Nowo\UptimeMonitorBundle\Repository\IncidentRepository;
use Nowo\UptimeMonitorBundle\Repository\MonitorRepository;
use Nowo\UptimeMonitorBundle\Service\AggregateService;
use Nowo\UptimeMonitorBundle\Service\CheckExecutorService;
use Nowo\UptimeMonitorBundle\Service\CheckLatencyNormalizer;
use Nowo\UptimeMonitorBundle\Service\DashboardSyncDispatcher;
use Nowo\UptimeMonitorBundle\Service\MonitorRetryService;
use Nowo\UptimeMonitorBundle\Service\NotificationService;
use Nowo\UptimeMonitorBundle\Service\StatusTransitionService;
use Nowo\UptimeMonitorBundle\Service\SummaryPayloadBuilder;
use Nowo\UptimeMonitorBundle\Service\TenantDashboardSerializer;
use Nowo\UptimeMonitorBundle\Service\UptimeMetricsService;
use Nowo\UptimeMonitorBundle\Tests\Support\SingleManagerRegistry;
use Nowo\UptimeMonitorBundle\Transaction\UptimeTransactionRunner;
use RuntimeException;

use function getenv;
use function is_dir;
use function is_string;
use function mkdir;
use function sprintf;
use function sys_get_temp_dir;

use const CASE_LOWER;
use const PHP_VERSION_ID;

/**
 * 1.29 (Beacon spec 239): what the real-server tests of tests/Database share with the processes they start.
 *
 * The server comes from `UPTIME_TEST_DATABASE_URL` (MySQL / MariaDB or PostgreSQL); without it the tests are skipped
 * (the CI `Load readiness` job runs them with `--fail-on-skipped`).
 */
final class DatabaseSupport
{
    public const URL_ENV = 'UPTIME_TEST_DATABASE_URL';

    /**
     * The server URL; null when none is configured.
     */
    public static function url(): ?string
    {
        $url = getenv(self::URL_ENV);

        return is_string($url) && $url !== '' ? $url : null;
    }

    /**
     * A new connection to the server.
     */
    public static function connection(string $url): Connection
    {
        $connection = DriverManager::getConnection((new DsnParser(['mysql' => 'pdo_mysql', 'postgresql' => 'pdo_pgsql', 'postgres' => 'pdo_pgsql', 'pgsql' => 'pdo_pgsql']))->parse($url));
        // DBAL 3: nested transactions with savepoints, as the bundle requires there (`use_savepoints: true`; DBAL 4 always).
        if (!$connection->getNestTransactionsWithSavepoints()) {
            $connection->setNestTransactionsWithSavepoints(true);
        }

        return $connection;
    }

    /**
     * An entity manager of the bundle entities on a new connection; `$resetSchema` drops and creates the bundle tables.
     */
    public static function entityManager(string $url, bool $resetSchema = false): EntityManagerInterface
    {
        $config = new Configuration();
        $config->setMetadataDriverImpl(new AttributeDriver([__DIR__ . '/../../../src/Entity']));
        $config->setNamingStrategy(new UnderscoreNamingStrategy(CASE_LOWER));
        $config->setProxyDir(self::proxyDir(sys_get_temp_dir() . '/nowo-uptime-monitor-load-proxies'));
        $config->setProxyNamespace('NowoUptimeMonitorLoadProxies');
        $config->enableNativeLazyObjects(PHP_VERSION_ID >= 80400);

        $em = new EntityManager(self::connection($url), $config);
        if ($resetSchema) {
            // Since 1.35: the share table (other tests) references uptime_monitor.
            $em->getConnection()->executeStatement('DROP TABLE IF EXISTS uptime_monitor_share');
            $metadata = $em->getMetadataFactory()->getAllMetadata();
            $tool     = new SchemaTool($em);
            $tool->dropSchema($metadata);
            $tool->createSchema($metadata);
        }

        return $em;
    }

    /**
     * The proxy directory, created up front (race-safe). Two worker processes starting at the same moment both let
     * Doctrine create a missing directory; the loser's mkdir failed and Doctrine threw "Your proxy directory ... must be
     * writable" (ORMInvalidArgumentException) in one of the processes (audit XZ-9, a harness race seen in fresh
     * containers on every database).
     */
    public static function proxyDir(string $directory): string
    {
        if (!is_dir($directory) && !@mkdir($directory, 0o777, true) && !is_dir($directory)) {
            throw new RuntimeException(sprintf('Cannot create the proxy directory "%s".', $directory));
        }

        return $directory;
    }

    /**
     * The bundle's private manager over an application manager of the bundle entities, as the bundle wires it (a
     * closed inner manager is replaced on the next call).
     */
    public static function privateManager(string $url): UptimeEntityManager
    {
        return new UptimeEntityManager(new SingleManagerRegistry(self::entityManager($url)));
    }

    /**
     * Statements of this connection wait at most `$seconds` for a row lock (then a lock wait timeout).
     */
    public static function lockTimeout(Connection $connection, int $seconds): void
    {
        $platform = $connection->getDatabasePlatform();
        match (true) {
            $platform instanceof AbstractMySQLPlatform => $connection->executeStatement(sprintf('SET SESSION innodb_lock_wait_timeout = %d', $seconds)),
            $platform instanceof PostgreSQLPlatform    => $connection->executeStatement(sprintf("SET lock_timeout = '%ds'", $seconds)),
            default                                    => throw new RuntimeException('MySQL / MariaDB or PostgreSQL only.'),
        };
    }

    /**
     * The bundle's check executor on the manager, with a check that answers `$status` for every monitor.
     *
     * @param callable(Monitor): CheckStatus $status
     */
    public static function executor(EntityManagerInterface $em, callable $status): CheckExecutorService
    {
        $registry = new SingleManagerRegistry($em);
        $runner   = new class($status) implements CheckRunnerInterface {
            /** @var callable(Monitor): CheckStatus */
            private $status;

            /**
             * @param callable(Monitor): CheckStatus $status
             */
            public function __construct(callable $status)
            {
                $this->status = $status;
            }

            public function supports(Monitor $monitor): bool
            {
                return true;
            }

            public function run(Monitor $monitor): CheckResultDto
            {
                $status = ($this->status)($monitor);

                return new CheckResultDto($status, 5, null, $status === CheckStatus::Up ? null : 'down');
            }
        };
        $transactions = $em instanceof UptimeEntityManager ? UptimeTransactionRunner::forBundle($em, new UptimeManagerRegistry($registry, $em)) : new UptimeTransactionRunner($em);

        return new CheckExecutorService(
            [$runner],
            $em,
            new AggregateService($em, new CheckAggregateRepository($registry), ['periods' => ['hour', 'day']]),
            new StatusTransitionService($em, new IncidentRepository($registry), new NotificationService([], ['enabled' => false]), [], transactionRunner: $transactions),
            new DashboardSyncDispatcher(new SummaryPayloadBuilder(new MonitorRepository($registry), new CheckResultRepository($registry), new UptimeMetricsService(), new TenantDashboardSerializer()), ['sync' => 'polling']),
            new CheckLatencyNormalizer(['min_latency_ms' => 0]),
            new MonitorRetryService(),
            transactionRunner: $transactions,
        );
    }

    /**
     * A registry over the manager (for the bundle repositories).
     */
    public static function registry(EntityManagerInterface $em): ManagerRegistry
    {
        return new SingleManagerRegistry($em);
    }
}
