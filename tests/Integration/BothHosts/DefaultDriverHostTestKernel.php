<?php

declare(strict_types=1);

namespace Nowo\UptimeMonitorBundle\Tests\Integration\BothHosts;

/**
 * DoctrineBundle 3.4 regression host: the application maps the bundle entities itself, from the bundle's XML, on a
 * dedicated "uptime" manager and connection named in `doctrine.entity_manager` (`register_mapping: false`); its
 * default manager, first in the registry, has neither `mappings` nor `auto_mapping`, so DoctrineBundle 3.4
 * `auto_discover_entities` gives its chain a default attribute driver that claims every `#[ORM\Entity]` class.
 */
class DefaultDriverHostTestKernel extends AbstractHostTestKernel
{
    protected function variant(): string
    {
        return 'default-driver-host';
    }

    protected function doctrineConfig(): array
    {
        return [
            'dbal' => [
                'default_connection' => 'default',
                'connections'        => [
                    'default' => ['url' => 'sqlite:///:memory:'],
                    'uptime'  => ['url' => 'sqlite:///:memory:'],
                ],
            ],
            'orm' => [
                'default_entity_manager' => 'default',
                'entity_managers'        => [
                    'default' => ['connection' => 'default'],
                    'uptime'  => ['connection' => 'uptime', 'mappings' => ['Uptime' => [
                        'type'      => 'xml',
                        'is_bundle' => false,
                        'dir'       => '%kernel.project_dir%/src/Resources/config/doctrine',
                        'prefix'    => 'Nowo\\UptimeMonitorBundle\\Entity',
                    ]]],
                ],
            ],
        ];
    }

    protected function bundleDoctrineConfig(): array
    {
        return ['entity_manager' => 'uptime', 'register_mapping' => false, 'flush_listener_changes' => false];
    }
}
