<?php

declare(strict_types=1);

namespace Nowo\UptimeMonitorBundle\Tests\Unit\Share;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Platforms\MariaDBPlatform;
use Doctrine\DBAL\Platforms\MySQL80Platform;
use Doctrine\DBAL\Platforms\MySQLPlatform;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Platforms\SQLitePlatform;
use Doctrine\DBAL\Schema\Column;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Schema\Table;
use Doctrine\DBAL\Types\Type;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Mapping\ClassMetadata;
use Doctrine\ORM\Tools\Event\GenerateSchemaTableEventArgs;
use InvalidArgumentException;
use Nowo\UptimeMonitorBundle\Doctrine\ExactUserIdentifierCollationListener;
use Nowo\UptimeMonitorBundle\Entity\Monitor;
use Nowo\UptimeMonitorBundle\Entity\Tenant;
use Nowo\UptimeMonitorBundle\Enum\MonitorType;
use Nowo\UptimeMonitorBundle\Query\MonitorRef;
use Nowo\UptimeMonitorBundle\Share\Entity\MonitorShare;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(ExactUserIdentifierCollationListener::class)]
#[CoversClass(MonitorRef::class)]
#[CoversClass(MonitorShare::class)]
final class ShareValueObjectsTest extends TestCase
{
    public function testTheExactCollationPerPlatformAndVersion(): void
    {
        self::assertSame('utf8mb4_nopad_bin', ExactUserIdentifierCollationListener::collationFor(new MariaDBPlatform()));
        self::assertSame('utf8mb4_0900_bin', ExactUserIdentifierCollationListener::collationFor(new MySQL80Platform()));
        self::assertSame('utf8mb4_0900_bin', ExactUserIdentifierCollationListener::collationFor(new MySQL80Platform(), '8.4.3'));
        self::assertSame('utf8mb4_0900_bin', ExactUserIdentifierCollationListener::collationFor(new MySQL80Platform(), 'unknown'));
        self::assertSame('utf8mb4_bin', ExactUserIdentifierCollationListener::collationFor(new MySQL80Platform(), '8.0.16'));
        self::assertSame('utf8mb4_bin', ExactUserIdentifierCollationListener::collationFor(new MySQL80Platform(), '8'));
        self::assertSame('utf8mb4_bin', ExactUserIdentifierCollationListener::collationFor(new MySQLPlatform()));
        self::assertNull(ExactUserIdentifierCollationListener::collationFor(new PostgreSQLPlatform()));
        self::assertNull(ExactUserIdentifierCollationListener::collationFor(new SQLitePlatform()));
    }

    public function testTheListenerCollatesTheShareColumnInPlace(): void
    {
        // The configured server version, else (no serverVersion parameter) the one the server reports.
        foreach ([[['serverVersion' => '8.4.3'], null, 'utf8mb4_0900_bin'], [['primary' => ['serverVersion' => '8.0.10']], null, 'utf8mb4_bin'], [[], '8.0.16', 'utf8mb4_bin'], [[], '8.4.0', 'utf8mb4_0900_bin']] as [$params, $reported, $expected]) {
            $table = $this->table();
            $args  = new GenerateSchemaTableEventArgs($this->metadata(MonitorShare::class), new Schema(), $table);

            (new ExactUserIdentifierCollationListener($this->manager(new MySQL80Platform(), $params, $reported)))->postGenerateSchemaTable($args);

            self::assertSame($table, $args->getClassTable(), 'the table is never replaced (its foreign keys are added later)');
            self::assertSame($expected, $table->getColumn('user_identifier')->getPlatformOption('collation'));
            self::assertSame('utf8mb4', $table->getColumn('user_identifier')->getPlatformOption('charset'));
        }
    }

    public function testTheListenerLeavesOtherTablesAndPlatformsAlone(): void
    {
        $table = $this->table();
        (new ExactUserIdentifierCollationListener($this->manager(new MySQL80Platform(), [])))->postGenerateSchemaTable(new GenerateSchemaTableEventArgs($this->metadata(Monitor::class), new Schema(), $table));
        self::assertFalse($table->getColumn('user_identifier')->hasPlatformOption('collation'));

        (new ExactUserIdentifierCollationListener($this->manager(new PostgreSQLPlatform(), [])))->postGenerateSchemaTable(new GenerateSchemaTableEventArgs($this->metadata(MonitorShare::class), new Schema(), $table));
        self::assertFalse($table->getColumn('user_identifier')->hasPlatformOption('collation'));
    }

    public function testAMonitorRefNeedsAPositiveId(): void
    {
        self::assertSame(['acme', 3], [(new MonitorRef('acme', 3))->tenantSlug, (new MonitorRef('acme', 3))->monitorId]);

        $this->expectException(InvalidArgumentException::class);
        new MonitorRef('acme', 0);
    }

    public function testTheShareEntity(): void
    {
        $monitor = new Monitor(new Tenant('acme', 'Acme'), 'API', MonitorType::Https, 'https://x.test');
        $at      = new DateTimeImmutable('2026-11-21 10:00:00');
        $share   = new MonitorShare($monitor, 'user-1', $at);

        self::assertNull($share->getId());
        self::assertSame([$monitor, 'user-1', $at], [$share->getMonitor(), $share->getUserIdentifier(), $share->getCreatedAt()]);
    }

    private function table(): Table
    {
        return new Table('uptime_monitor_share', [new Column('user_identifier', Type::getType(Types::STRING), ['length' => 128])]);
    }

    /**
     * @param class-string $class
     *
     * @return ClassMetadata<object>
     */
    private function metadata(string $class): ClassMetadata
    {
        $metadata = new ClassMetadata($class);
        $metadata->mapField(['fieldName' => 'userIdentifier', 'columnName' => 'user_identifier', 'type' => Types::STRING]);

        return $metadata;
    }

    /**
     * @param array<string, mixed> $params
     * @param string|null $reportedVersion What the server reports (asked only without a configured version)
     */
    private function manager(object $platform, array $params, ?string $reportedVersion = null): EntityManagerInterface
    {
        $connection = $this->createStub(Connection::class);
        $connection->method('getDatabasePlatform')->willReturn($platform);
        $connection->method('getParams')->willReturn($params);
        if ($reportedVersion !== null) {
            $connection->method('getServerVersion')->willReturn($reportedVersion);
        }
        $manager = $this->createStub(EntityManagerInterface::class);
        $manager->method('getConnection')->willReturn($connection);

        return $manager;
    }
}
