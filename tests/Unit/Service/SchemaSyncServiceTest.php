<?php

declare(strict_types=1);

namespace Nowo\UptimeMonitorBundle\Tests\Unit\Service;

use Exception;
use Nowo\UptimeMonitorBundle\Service\SchemaSyncService;
use PHPUnit\Framework\TestCase;

/**
 * @covers \Nowo\UptimeMonitorBundle\Service\SchemaSyncService
 */
final class SchemaSyncServiceTest extends TestCase
{
    public function testFilterCreateStatementsSkipsExistingTables(): void
    {
        $service = new SchemaSyncService(
            $this->createMock(\Doctrine\ORM\EntityManagerInterface::class),
        );

        $sql = [
            'CREATE TABLE uptime_tag (id INT NOT NULL)',
            'CREATE TABLE uptime_tenant (id INT NOT NULL)',
        ];

        $filtered = $service->filterCreateStatementsForExistingTables($sql, ['uptime_tag']);

        self::assertSame(['CREATE TABLE uptime_tenant (id INT NOT NULL)'], $filtered);
    }

    public function testFilterCreateStatementsHandlesQuotedSchemaTable(): void
    {
        $service = new SchemaSyncService(
            $this->createMock(\Doctrine\ORM\EntityManagerInterface::class),
        );

        $sql = ['CREATE TABLE public."uptime_tag" (id INT NOT NULL)'];

        $filtered = $service->filterCreateStatementsForExistingTables($sql, ['uptime_tag']);

        self::assertSame([], $filtered);
    }

    public function testIsDuplicateSchemaObjectExceptionDetectsPostgresCode(): void
    {
        $service = new SchemaSyncService(
            $this->createMock(\Doctrine\ORM\EntityManagerInterface::class),
        );

        $exception = new \RuntimeException(
            'SQLSTATE[42P07]: Duplicate table: relation "idx_test" already exists',
            0,
            new Exception('SQLSTATE[42P07]', 7),
        );

        self::assertTrue($service->isDuplicateSchemaObjectException($exception));
    }
}
