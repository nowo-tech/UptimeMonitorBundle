<?php

declare(strict_types=1);

namespace Nowo\UptimeMonitorBundle\Doctrine;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\QueryBuilder;

use function array_map;
use function count;
use function is_numeric;
use function max;

/**
 * Deletes the rows of one entity that match a filter in short primary-key (keyset) batches.
 *
 * Each batch is two short DQL statements outside the unit of work (autocommit unless the caller opened a
 * transaction): `SELECT e.id … WHERE <filter> AND e.id > :afterId ORDER BY e.id` with the platform limit
 * (`setMaxResults()` → `AbstractPlatform::modifyLimitQuery()`), then `DELETE … WHERE e.id IN (:ids)`. No statement
 * holds locks / undo / binlog for the whole set (unlike one unbounded `DELETE … WHERE …`), and the keyset bound means
 * rows are never rescanned. It stops after the first short batch.
 *
 * @internal used by the retention purge and the clear-data command; not part of the public API
 */
final class KeysetBatchDelete
{
    public const DEFAULT_BATCH_SIZE = 1000;

    /** DQL alias the filter callback must use for the entity. */
    public const ALIAS = 'e';

    /**
     * Reads `purge_batch_size` from the `retention` config array (falls back to {@see self::DEFAULT_BATCH_SIZE}).
     *
     * @param array<string, mixed> $retentionConfig
     */
    public static function batchSizeFrom(array $retentionConfig): int
    {
        $size = $retentionConfig['purge_batch_size'] ?? null;

        return is_numeric($size) && (int) $size >= 1 ? (int) $size : self::DEFAULT_BATCH_SIZE;
    }

    /**
     * @param class-string $entityClass entity with a single integer `id` identifier
     * @param (callable(QueryBuilder, string): void)|null $filter adds `andWhere()` / `setParameter()` on {@see self::ALIAS}
     *
     * @return int number of deleted rows
     */
    public static function run(
        EntityManagerInterface $entityManager,
        string $entityClass,
        ?callable $filter = null,
        int $batchSize = self::DEFAULT_BATCH_SIZE,
    ): int {
        $batchSize = max(1, $batchSize);
        $alias     = self::ALIAS;
        $afterId   = null;
        $deleted   = 0;

        do {
            $select = $entityManager->createQueryBuilder()
                ->select($alias . '.id')
                ->from($entityClass, $alias)
                ->orderBy($alias . '.id', 'ASC')
                ->setMaxResults($batchSize);

            if ($filter !== null) {
                $filter($select, $alias);
            }

            if ($afterId !== null) {
                $select
                    ->andWhere($alias . '.id > :keysetAfterId')
                    ->setParameter('keysetAfterId', $afterId);
            }

            $ids = array_map(static fn (mixed $id): int => (int) $id, $select->getQuery()->getSingleColumnResult());
            if ($ids === []) {
                break;
            }

            $afterId = $ids[count($ids) - 1];
            $deleted += (int) $entityManager->createQueryBuilder()
                ->delete($entityClass, $alias)
                ->where($alias . '.id IN (:keysetIds)')
                ->setParameter('keysetIds', $ids)
                ->getQuery()
                ->execute();
        } while (count($ids) === $batchSize);

        return $deleted;
    }
}
