<?php

declare(strict_types=1);

namespace Nowo\UptimeMonitorBundle\Tests\Unit\Query;

use DateTime;
use DateTimeImmutable;
use Doctrine\DBAL\Platforms\MariaDBPlatform;
use Doctrine\DBAL\Platforms\MySQL80Platform;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Platforms\SQLitePlatform;
use Doctrine\DBAL\Platforms\SQLServerPlatform;
use LogicException;
use Nowo\UptimeMonitorBundle\Query\IncidentDowntimeSql;
use Nowo\UptimeMonitorBundle\Query\ReadRow;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * 1.36: the scalar conversions of the report rows and the per-platform downtime SQL.
 */
#[CoversClass(ReadRow::class)]
#[CoversClass(IncidentDowntimeSql::class)]
final class ReportReadHelpersTest extends TestCase
{
    public function testRowValuesConvertWhateverTheDriverReturns(): void
    {
        self::assertSame(12, ReadRow::int('12'));
        self::assertSame(3, ReadRow::int(3.9));
        self::assertSame(0, ReadRow::int(null), 'a SUM over no row');
        self::assertSame(0, ReadRow::int('x'));
        self::assertSame('alpha', ReadRow::string('alpha'));
        self::assertSame('42', ReadRow::string(42));
        self::assertSame('', ReadRow::string([]));

        $immutable = new DateTimeImmutable('2026-11-21 10:00:00');
        self::assertSame($immutable, ReadRow::date($immutable));
        self::assertSame('2026-11-21 10:00:00', ReadRow::date(new DateTime('2026-11-21 10:00:00'))?->format('Y-m-d H:i:s'));
        self::assertSame('2026-11-21 10:00:00', ReadRow::date('2026-11-21 10:00:00')?->format('Y-m-d H:i:s'));
        self::assertNull(ReadRow::date(''));
        self::assertNull(ReadRow::date(null));
        self::assertNull(ReadRow::date(5));
    }

    public function testTheDowntimeSqlFollowsThePlatform(): void
    {
        self::assertSame(':from', IncidentDowntimeSql::bound(new MySQL80Platform(), 'from'));
        self::assertSame('CAST(:to AS TIMESTAMP)', IncidentDowntimeSql::bound(new PostgreSQLPlatform(), 'to'));
        self::assertStringStartsWith('GREATEST(TIMESTAMPDIFF(SECOND, GREATEST(s, :from), LEAST(COALESCE(e, :now), :to))', IncidentDowntimeSql::seconds(new MariaDBPlatform(), 's', 'e'));
        self::assertStringContainsString('EXTRACT(EPOCH FROM (LEAST(COALESCE(e, CAST(:now AS TIMESTAMP)), CAST(:to AS TIMESTAMP)) - GREATEST(s, CAST(:from AS TIMESTAMP))))', IncidentDowntimeSql::seconds(new PostgreSQLPlatform(), 's', 'e'));
        self::assertStringContainsString("strftime('%s', MAX(s, :from))", IncidentDowntimeSql::seconds(new SQLitePlatform(), 's', 'e'));

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('MySQL, MariaDB, PostgreSQL or SQLite');
        IncidentDowntimeSql::seconds(new SQLServerPlatform(), 's', 'e');
    }
}
