<?php

declare(strict_types=1);

namespace Nowo\UptimeMonitorBundle\Tests\Integration\Service;

use Doctrine\DBAL\Exception\InvalidArgumentException as DbalInvalidArgumentException;
use Nowo\UptimeMonitorBundle\Service\SchemaSyncService;
use Nowo\UptimeMonitorBundle\Tests\Support\SqliteEntityManagerFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Throwable;

use function array_filter;
use function array_values;
use function count;

#[Group('db')]
#[CoversClass(SchemaSyncService::class)]
final class SchemaSyncServiceDbTest extends TestCase
{
    public function testEmptyDatabaseGetsEveryCreateStatement(): void
    {
        $service = new SchemaSyncService(SqliteEntityManagerFactory::create(createSchema: false));

        $create = $service->getCreateSchemaSql();
        self::assertNotEmpty($create);
        self::assertSame($create, $service->getCreateMissingSchemaSql());
        self::assertNotEmpty($service->getSyncSchemaSql());
    }

    public function testExistingTablesAndIndexesAreSkipped(): void
    {
        $em      = SqliteEntityManagerFactory::create(createSchema: false);
        $service = new SchemaSyncService($em);

        // Create only the tenant table (no foreign keys) and its index, as a partial install would have.
        $tenantPattern = '/^CREATE TABLE uptime_tenant\b|^CREATE (UNIQUE )?INDEX \w+ ON uptime_tenant\b/';
        $monitorSql    = array_values(array_filter(
            $service->getCreateSchemaSql(),
            static fn (string $sql): bool => preg_match($tenantPattern, $sql) === 1,
        ));
        self::assertNotEmpty($monitorSql);
        foreach ($monitorSql as $sql) {
            $em->getConnection()->executeStatement($sql);
        }

        $missing = $service->getCreateMissingSchemaSql();
        self::assertCount(count($service->getCreateSchemaSql()) - count($monitorSql), $missing);
        foreach ($missing as $sql) {
            self::assertDoesNotMatchRegularExpression($tenantPattern, $sql);
        }

        foreach ($missing as $sql) {
            $em->getConnection()->executeStatement($sql);
        }
        // No CREATE is left; MySQL / PostgreSQL still list the foreign-key ALTERs (applied as duplicates by the command).
        foreach ($service->getCreateMissingSchemaSql() as $sql) {
            self::assertStringStartsWith('ALTER TABLE', $sql);
        }
        // The additive update may hold platform-specific no-op ALTERs (MySQL / PostgreSQL), never a CREATE TABLE.
        foreach ($service->getSyncSchemaSql() as $sql) {
            self::assertStringNotContainsString('CREATE TABLE', $sql);
        }
    }

    public function testFilterSkipsExistingSequencesAndKeepsOtherStatements(): void
    {
        $service = new SchemaSyncService(SqliteEntityManagerFactory::create());

        self::assertSame(
            ['CREATE SEQUENCE new_seq', 'ALTER TABLE uptime_tag ADD extra INT'],
            $service->filterExistingSchemaObjects([
                'CREATE TABLE uptime_tag (id INT)',
                'CREATE INDEX idx_uptime_monitor_tenant_next ON uptime_monitor (tenant_id)',
                'CREATE SEQUENCE new_seq',
                'ALTER TABLE uptime_tag ADD extra INT',
            ]),
        );
        self::assertSame(
            ['CREATE UNIQUE INDEX other_idx ON t (a)'],
            $service->filterCreateStatementsForExistingTables(['CREATE UNIQUE INDEX other_idx ON t (a)'], ['t']),
        );
    }

    public function testSyncSchemaSqlNeverTouchesHostTables(): void
    {
        $em         = SqliteEntityManagerFactory::create();
        $connection = $em->getConnection();
        $service    = new SchemaSyncService($em);
        $connection->executeStatement('DROP TABLE IF EXISTS host_customer');
        $connection->executeStatement('CREATE TABLE host_customer (id INTEGER NOT NULL PRIMARY KEY, name VARCHAR(50))');
        $connection->executeStatement('CREATE INDEX idx_host_customer_name ON host_customer (name)');

        try {
            foreach ([$service->getUpdateSchemaSql(), $service->getSyncSchemaSql()] as $statements) {
                $sql = implode("\n", $statements);
                self::assertStringNotContainsStringIgnoringCase('host_customer', $sql);
                self::assertStringNotContainsStringIgnoringCase('DROP TABLE', $sql);
                self::assertStringNotContainsStringIgnoringCase('DROP SEQUENCE', $sql);
            }

            // As nowo:uptime:sync-schema does: duplicate foreign keys (MySQL / PostgreSQL no-op ALTERs) are skipped.
            foreach ($service->getSyncSchemaSql() as $sql) {
                try {
                    $connection->executeStatement($sql);
                } catch (Throwable $e) {
                    self::assertTrue($service->isDuplicateSchemaObjectException($e), $e->getMessage());
                }
            }

            self::assertTrue($connection->createSchemaManager()->tablesExist(['host_customer']));
        } finally {
            $connection->executeStatement('DROP TABLE IF EXISTS host_customer');
        }
    }

    public function testUpdateSchemaSqlRestoresAMissingIndexOfABundleTable(): void
    {
        $em = SqliteEntityManagerFactory::create();
        $em->getConnection()->createSchemaManager()->dropIndex('uniq_uptime_tenant_slug', 'uptime_tenant');

        self::assertStringContainsStringIgnoringCase(
            'uniq_uptime_tenant_slug',
            implode("\n", (new SchemaSyncService($em))->getUpdateSchemaSql()),
        );
    }

    public function testDuplicateSchemaObjectDetection(): void
    {
        $service = new SchemaSyncService(SqliteEntityManagerFactory::create());

        self::assertTrue($service->isDuplicateSchemaObjectException(new DbalInvalidArgumentException('Table exists', 1050)));
        self::assertTrue($service->isDuplicateSchemaObjectException(new RuntimeException('wrapped', 0, new RuntimeException('Duplicate column name "x"'))));
        self::assertTrue($service->isDuplicateSchemaObjectException(new RuntimeException('duplicate key value violates unique constraint')));
        self::assertFalse($service->isDuplicateSchemaObjectException(new DbalInvalidArgumentException('Syntax error', 1064)));
        self::assertFalse($service->isDuplicateSchemaObjectException(new RuntimeException('Connection refused')));
    }
}
