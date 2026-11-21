<?php

declare(strict_types=1);

namespace Nowo\UptimeMonitorBundle\Doctrine;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Platforms\MariaDBPlatform;
use Doctrine\DBAL\Platforms\MySQL80Platform;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\Event\GenerateSchemaTableEventArgs;
use Nowo\UptimeMonitorBundle\Share\Entity\MonitorShare;
use Nowo\UptimeMonitorBundle\Share\MonitorShares;
use ReflectionMethod;

use function is_array;
use function is_string;
use function preg_match;
use function version_compare;

/**
 * Since 1.35 (exact-key rule, REQ-RUNTIME-003 rule 5): binary NO PAD collation on the `user_identifier` column of
 * {@see MonitorShare} on MySQL / MariaDB, so `UserA` never matches `usera` or `usera ` in SQL. MariaDB gets
 * `utf8mb4_nopad_bin`, MySQL 8.0.17+ `utf8mb4_0900_bin`, older MySQL `utf8mb4_bin` (PAD SPACE: the PHP re-check of
 * {@see MonitorShares} covers it). PostgreSQL and SQLite compare exactly and are left
 * alone (a `collation` mapping option is not portable).
 *
 * Applied when the schema is generated from the mapping (`nowo:uptime:sync-schema`, `doctrine:migrations:diff`,
 * `doctrine:schema:update`). Registered only with `shares.enabled`. Stateless: the platform is read on each event.
 *
 * @internal
 */
final readonly class ExactUserIdentifierCollationListener
{
    /** Fallback binary collation (MySQL < 8.0.17); PAD SPACE. */
    public const MYSQL_LEGACY_COLLATION = 'utf8mb4_bin';

    /** MySQL 8.0.17+ binary NO PAD collation. */
    public const MYSQL_COLLATION = 'utf8mb4_0900_bin';

    /** MariaDB binary NO PAD collation. */
    public const MARIADB_COLLATION = 'utf8mb4_nopad_bin';

    /** The column made exact. */
    public const COLUMN_FIELD = 'userIdentifier';

    /**
     * @param EntityManagerInterface $entityManager The bundle's manager (its connection holds the bundle tables)
     */
    public function __construct(
        private EntityManagerInterface $entityManager,
    ) {
    }

    /**
     * The exact collation for the platform and server version; null when the platform already compares exactly.
     *
     * @return non-empty-string|null
     */
    public static function collationFor(AbstractPlatform $platform, ?string $serverVersion = null): ?string
    {
        return match (true) {
            $platform instanceof MariaDBPlatform       => self::MARIADB_COLLATION,
            $platform instanceof MySQL80Platform       => self::supportsNoPad($serverVersion) ? self::MYSQL_COLLATION : self::MYSQL_LEGACY_COLLATION,
            $platform instanceof AbstractMySQLPlatform => self::MYSQL_LEGACY_COLLATION,
            default                                    => null,
        };
    }

    /**
     * Gives the share table's `user_identifier` column the exact collation.
     */
    public function postGenerateSchemaTable(GenerateSchemaTableEventArgs $args): void
    {
        $metadata = $args->getClassMetadata();
        if ($metadata->getName() !== MonitorShare::class || !$metadata->hasField(self::COLUMN_FIELD)) {
            return;
        }
        $connection = $this->entityManager->getConnection();
        $platform   = $connection->getDatabasePlatform();
        $collation  = self::collationFor($platform, $platform instanceof MySQL80Platform ? $this->serverVersion($connection) : null);
        $column     = $metadata->getColumnName(self::COLUMN_FIELD);
        $table      = $args->getClassTable();
        if ($collation === null || $column === '' || !$table->hasColumn($column)) {
            return;
        }

        // In place, on purpose: doctrine/orm 3.8 adds the foreign keys to the table object it built, so a table replaced
        // through setClassTable() (DBAL 4.5 table editor) would lose the share's foreign key to the monitor.
        $table->getColumn($column)->setPlatformOption('charset', 'utf8mb4')->setPlatformOption('collation', $collation);
    }

    /**
     * The configured server version, else the one the server reports (null when unknown).
     */
    private function serverVersion(Connection $connection): ?string
    {
        $params  = $connection->getParams();
        $primary = $params['primary'] ?? null;
        $version = $params['serverVersion'] ?? (is_array($primary) ? ($primary['serverVersion'] ?? null) : null);
        if (is_string($version) && $version !== '') {
            return $version;
        }
        if ((new ReflectionMethod($connection, 'getServerVersion'))->isPublic()) {
            return $connection->getServerVersion();
        }

        return null; // @codeCoverageIgnore
    }

    private static function supportsNoPad(?string $serverVersion): bool
    {
        if ($serverVersion === null || preg_match('/\A(\d+)(?:\.(\d+))?(?:\.(\d+))?/', $serverVersion, $match) !== 1) {
            return true;
        }

        return version_compare($match[1] . '.' . (($match[2] ?? '') !== '' ? $match[2] : '0') . '.' . (($match[3] ?? '') !== '' ? $match[3] : '0'), '8.0.17', '>=');
    }
}
