<?php

declare(strict_types=1);

namespace Nowo\UptimeMonitorBundle\Tests\Integration\BothHosts;

/**
 * DDD host: the application maps only its own domain, in XML, on the default manager (no auto_mapping), and maps the
 * bundle entities itself, from the bundle's XML directory, on a dedicated "uptime" manager and connection.
 * `doctrine.register_mapping: false` and `doctrine.flush_listener_changes: false`.
 */
final class DddHostTestKernel extends AbstractHostTestKernel
{
    protected function variant(): string
    {
        return 'ddd-host';
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
                    'default' => ['connection' => 'default', 'mappings' => ['App' => [
                        'type'      => 'xml',
                        'is_bundle' => false,
                        'dir'       => '%kernel.project_dir%/tests/Fixtures/Host/config/doctrine',
                        'prefix'    => 'Nowo\\UptimeMonitorBundle\\Tests\\Fixtures\\Host\\Entity',
                    ]]],
                    'uptime' => ['connection' => 'uptime', 'mappings' => ['Uptime' => [
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
        return ['register_mapping' => false, 'flush_listener_changes' => false];
    }
}
