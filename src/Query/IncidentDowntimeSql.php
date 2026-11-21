<?php

declare(strict_types=1);

namespace Nowo\UptimeMonitorBundle\Query;

use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Platforms\SQLitePlatform;
use LogicException;

use function sprintf;

/**
 * The SQL of {@see DoctrineIncidentQuery::downtimeByMonitor()} per platform (since 1.36): the seconds of an incident
 * inside the window `[:from, :to)`, an open incident running until `:now`, never negative. DQL has no date difference
 * in seconds, so the expression is native: MySQL / MariaDB, PostgreSQL and SQLite.
 *
 * @internal
 */
final class IncidentDowntimeSql
{
    /**
     * A bound date parameter (`:from`, `:to`, `:now`), typed on PostgreSQL.
     */
    public static function bound(AbstractPlatform $platform, string $name): string
    {
        return $platform instanceof PostgreSQLPlatform ? 'CAST(:' . $name . ' AS TIMESTAMP)' : ':' . $name;
    }

    /**
     * The seconds expression over the `$started` and `$ended` columns.
     *
     * @throws LogicException On another platform
     */
    public static function seconds(AbstractPlatform $platform, string $started, string $ended): string
    {
        $from = self::bound($platform, 'from');
        $to   = self::bound($platform, 'to');
        $now  = self::bound($platform, 'now');

        return match (true) {
            $platform instanceof AbstractMySQLPlatform => sprintf('GREATEST(TIMESTAMPDIFF(SECOND, GREATEST(%1$s, %3$s), LEAST(COALESCE(%2$s, %5$s), %4$s)), 0)', $started, $ended, $from, $to, $now),
            $platform instanceof PostgreSQLPlatform    => sprintf('GREATEST(CAST(EXTRACT(EPOCH FROM (LEAST(COALESCE(%2$s, %5$s), %4$s) - GREATEST(%1$s, %3$s))) AS BIGINT), 0)', $started, $ended, $from, $to, $now),
            $platform instanceof SQLitePlatform        => sprintf("MAX(CAST(strftime('%%s', MIN(COALESCE(%2\$s, %5\$s), %4\$s)) AS INTEGER) - CAST(strftime('%%s', MAX(%1\$s, %3\$s)) AS INTEGER), 0)", $started, $ended, $from, $to, $now),
            default                                    => throw new LogicException(sprintf('Incident downtime totals need MySQL, MariaDB, PostgreSQL or SQLite; the connection runs %s.', $platform::class)),
        };
    }
}
