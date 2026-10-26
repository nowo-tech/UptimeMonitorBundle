<?php

declare(strict_types=1);

namespace Nowo\UptimeMonitorBundle\Tests\Support;

use DateTimeImmutable;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Tools\DsnParser;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Configuration;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Mapping\Driver\AttributeDriver;
use Doctrine\ORM\Mapping\UnderscoreNamingStrategy;
use Doctrine\ORM\Tools\SchemaTool;
use Nowo\UptimeMonitorBundle\Entity\CheckAggregate;
use Nowo\UptimeMonitorBundle\Entity\CheckResult;
use Nowo\UptimeMonitorBundle\Entity\Incident;
use Nowo\UptimeMonitorBundle\Entity\Monitor;
use Nowo\UptimeMonitorBundle\Enum\AggregatePeriod;
use Nowo\UptimeMonitorBundle\Enum\CheckStatus;

use function array_map;
use function getenv;
use function is_string;
use function sprintf;
use function sys_get_temp_dir;

use const CASE_LOWER;
use const PHP_VERSION_ID;

/**
 * EntityManager with the bundle schema, for tests that need real DQL / batching behaviour.
 *
 * Default: in-memory SQLite. When {@see self::DATABASE_URL_ENV} is set (`make test-with-db`, REQ-TEST-005) the same
 * tests run against that real database (MySQL / PostgreSQL from the root docker-compose.yml): the bundle tables are
 * dropped and recreated on every call, so each test starts from an empty schema.
 */
final class SqliteEntityManagerFactory
{
    public const DATABASE_URL_ENV = 'UPTIME_TEST_DATABASE_URL';

    public static function create(?\Doctrine\DBAL\Configuration $dbalConfig = null): EntityManagerInterface
    {
        $config = new Configuration();
        $config->setMetadataDriverImpl(new AttributeDriver([__DIR__ . '/../../src/Entity']));
        $config->setNamingStrategy(new UnderscoreNamingStrategy(CASE_LOWER));
        $config->setProxyDir(sys_get_temp_dir() . '/nowo-uptime-monitor-test-proxies');
        $config->setProxyNamespace('NowoUptimeMonitorTestProxies');
        // ORM 3.4+ on PHP 8.4+: proxies need native lazy objects when symfony/var-exporter LazyGhost is unavailable.
        $config->enableNativeLazyObjects(PHP_VERSION_ID >= 80400);

        $url    = getenv(self::DATABASE_URL_ENV);
        $params = is_string($url) && $url !== ''
            ? (new DsnParser(['mysql' => 'pdo_mysql', 'postgresql' => 'pdo_pgsql', 'postgres' => 'pdo_pgsql', 'pgsql' => 'pdo_pgsql']))->parse($url)
            : ['driver' => 'pdo_sqlite', 'memory' => true];

        $em       = new EntityManager(DriverManager::getConnection($params, $dbalConfig), $config);
        $metadata = $em->getMetadataFactory()->getAllMetadata();
        $tool     = new SchemaTool($em);
        $tool->dropSchema($metadata);
        $tool->createSchema($metadata);

        return $em;
    }

    /**
     * Persists $count check results for $monitor, all with checked_at = $checkedAt.
     */
    public static function addCheckResults(EntityManagerInterface $em, Monitor $monitor, int $count, DateTimeImmutable $checkedAt): void
    {
        $results = [];
        for ($i = 0; $i < $count; ++$i) {
            $results[] = $result = new CheckResult($monitor, CheckStatus::Up, 10);
            $em->persist($result);
        }
        $em->flush();

        $em->getConnection()->executeStatement(
            'UPDATE uptime_check_result SET checked_at = ? WHERE id IN (?)',
            [$checkedAt, array_map(static fn (CheckResult $r): ?int => $r->getId(), $results)],
            [Types::DATETIME_IMMUTABLE, ArrayParameterType::INTEGER],
        );
    }

    public static function addAggregatesAndIncidents(EntityManagerInterface $em, Monitor $monitor, int $count): void
    {
        for ($i = 0; $i < $count; ++$i) {
            $em->persist(new CheckAggregate($monitor, AggregatePeriod::Hour, new DateTimeImmutable(sprintf('2026-01-01 00:00:00 +%d hours', $i))));
            $em->persist(new Incident($monitor, CheckStatus::Down));
        }
        $em->flush();
    }

    public static function count(EntityManagerInterface $em, string $table): int
    {
        return (int) $em->getConnection()->fetchOne('SELECT COUNT(*) FROM ' . $table);
    }
}
