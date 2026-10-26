<?php

declare(strict_types=1);

namespace Nowo\UptimeMonitorBundle\Doctrine;

use Doctrine\ORM\Event\LoadClassMetadataEventArgs;
use Doctrine\ORM\Mapping\ClassMetadata;
use Nowo\UptimeMonitorBundle\DependencyInjection\UptimeMonitorExtension;

use function is_array;
use function is_string;
use function str_starts_with;

/**
 * Prepends `doctrine.table_prefix` to the tables, indexes and unique constraints of the bundle entities
 * (REQ-ORM-002). Entities outside {@see self::ENTITY_NAMESPACE} are never touched; an empty prefix is a no-op, so the
 * default configuration keeps the historical `uptime_*` names.
 *
 * Registered by {@see UptimeMonitorExtension} as a Doctrine
 * `loadClassMetadata` listener; schema tooling (`nowo:uptime:sync-schema`) reads the same metadata, so it honours the
 * prefix too.
 */
final readonly class TablePrefixListener
{
    public const ENTITY_NAMESPACE = 'Nowo\\UptimeMonitorBundle\\Entity\\';

    /**
     * @param string $tablePrefix Prefix prepended to every bundle table / index / unique constraint name ('' = none)
     */
    public function __construct(
        private string $tablePrefix = '',
    ) {
    }

    /**
     * Rewrites the metadata of bundle entities when Doctrine loads it.
     */
    public function loadClassMetadata(LoadClassMetadataEventArgs $event): void
    {
        $this->apply($event->getClassMetadata());
    }

    /**
     * Applies the prefix to one class metadata (no-op for an empty prefix or a non-bundle entity).
     *
     * @param ClassMetadata<object> $metadata
     */
    public function apply(ClassMetadata $metadata): void
    {
        if ($this->tablePrefix === '' || !str_starts_with($metadata->getName(), self::ENTITY_NAMESPACE)) {
            return;
        }

        $table = [
            'name' => $this->tablePrefix . $metadata->getTableName(),
        ];

        foreach (['indexes', 'uniqueConstraints'] as $key) {
            $definitions = $metadata->table[$key] ?? null;
            if (!is_array($definitions) || $definitions === []) {
                continue;
            }

            $prefixed = [];
            foreach ($definitions as $name => $definition) {
                $prefixed[is_string($name) ? $this->tablePrefix . $name : $name] = $definition;
            }
            $table[$key] = $prefixed;
        }

        $metadata->setPrimaryTable($table);
    }
}
