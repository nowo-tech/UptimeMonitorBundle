<?php

declare(strict_types=1);

namespace Nowo\UptimeMonitorBundle\Tests\Unit\Doctrine;

use Doctrine\Common\EventManager;
use Doctrine\DBAL\DriverManager;
use Doctrine\ORM\Configuration;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Event\LoadClassMetadataEventArgs;
use Doctrine\ORM\Events;
use Doctrine\ORM\Mapping\ClassMetadata;
use Doctrine\ORM\Mapping\Driver\AttributeDriver;
use Doctrine\ORM\Mapping\UnderscoreNamingStrategy;
use Doctrine\ORM\Tools\SchemaTool;
use Nowo\UptimeMonitorBundle\Doctrine\TablePrefixListener;
use Nowo\UptimeMonitorBundle\Entity\CheckAggregate;
use Nowo\UptimeMonitorBundle\Entity\CheckResult;
use Nowo\UptimeMonitorBundle\Entity\Incident;
use Nowo\UptimeMonitorBundle\Entity\Monitor;
use Nowo\UptimeMonitorBundle\Entity\Tag;
use Nowo\UptimeMonitorBundle\Entity\Tenant;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use stdClass;

use function implode;
use function sys_get_temp_dir;

use const CASE_LOWER;
use const PHP_VERSION_ID;

#[CoversClass(TablePrefixListener::class)]
final class TablePrefixListenerTest extends TestCase
{
    public function testEmptyPrefixKeepsHistoricalNames(): void
    {
        $em = $this->entityManager('');

        self::assertSame('uptime_monitor', $em->getClassMetadata(Monitor::class)->getTableName());
        self::assertSame('uptime_check_result', $em->getClassMetadata(CheckResult::class)->getTableName());
        self::assertArrayHasKey('idx_uptime_monitor_tenant_next', $em->getClassMetadata(Monitor::class)->table['indexes'] ?? []);
    }

    public function testPrefixIsPrependedToEveryBundleTableIndexAndUniqueConstraint(): void
    {
        $em = $this->entityManager('app_');

        $expected = [
            Tenant::class         => 'app_uptime_tenant',
            Monitor::class        => 'app_uptime_monitor',
            CheckResult::class    => 'app_uptime_check_result',
            CheckAggregate::class => 'app_uptime_check_aggregate',
            Incident::class       => 'app_uptime_incident',
            Tag::class            => 'app_uptime_tag',
        ];
        foreach ($expected as $class => $table) {
            self::assertSame($table, $em->getClassMetadata($class)->getTableName(), $class);
        }

        self::assertArrayHasKey('app_idx_uptime_monitor_tenant_next', $em->getClassMetadata(Monitor::class)->table['indexes'] ?? []);
        self::assertArrayHasKey('app_uniq_uptime_tenant_slug', $em->getClassMetadata(Tenant::class)->table['uniqueConstraints'] ?? []);
        self::assertArrayHasKey('app_uniq_uptime_aggregate_bucket', $em->getClassMetadata(CheckAggregate::class)->table['uniqueConstraints'] ?? []);
    }

    public function testSchemaToolCreatesPrefixedTablesOnly(): void
    {
        $em  = $this->entityManager('app_');
        $sql = implode("\n", (new SchemaTool($em))->getCreateSchemaSql($em->getMetadataFactory()->getAllMetadata()));

        self::assertStringContainsString('CREATE TABLE app_uptime_monitor', $sql);
        self::assertStringContainsString('app_idx_uptime_check_monitor_checked', $sql);
        self::assertStringNotContainsString('CREATE TABLE uptime_', $sql);

        // The prefixed schema is usable end to end (foreign keys reference the prefixed tables).
        (new SchemaTool($em))->createSchema($em->getMetadataFactory()->getAllMetadata());
        self::assertSame(0, (int) $em->getConnection()->fetchOne('SELECT COUNT(*) FROM app_uptime_monitor'));
    }

    public function testNonBundleEntitiesAreIgnored(): void
    {
        $metadata = new ClassMetadata(stdClass::class);
        $metadata->setPrimaryTable(['name' => 'host_table', 'indexes' => ['idx_host' => ['columns' => ['a']]]]);

        $listener = new TablePrefixListener('app_');
        $listener->loadClassMetadata(new LoadClassMetadataEventArgs($metadata, $this->entityManager('')));

        self::assertSame('host_table', $metadata->getTableName());
        self::assertArrayHasKey('idx_host', $metadata->table['indexes'] ?? []);
    }

    private function entityManager(string $prefix): EntityManagerInterface
    {
        $config = new Configuration();
        $config->setMetadataDriverImpl(new AttributeDriver([__DIR__ . '/../../../src/Entity']));
        $config->setNamingStrategy(new UnderscoreNamingStrategy(CASE_LOWER));
        $config->setProxyDir(sys_get_temp_dir() . '/nowo-uptime-monitor-test-proxies');
        $config->setProxyNamespace('NowoUptimeMonitorTestProxies');
        $config->enableNativeLazyObjects(PHP_VERSION_ID >= 80400);

        $eventManager = new EventManager();
        $eventManager->addEventListener(Events::loadClassMetadata, new TablePrefixListener($prefix));

        return new EntityManager(
            DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]),
            $config,
            $eventManager,
        );
    }
}
