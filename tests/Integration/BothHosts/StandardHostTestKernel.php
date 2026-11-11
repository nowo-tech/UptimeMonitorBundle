<?php

declare(strict_types=1);

namespace Nowo\UptimeMonitorBundle\Tests\Integration\BothHosts;

/**
 * Standard host: one connection, `auto_mapping` on the default manager (the bundle registers its attribute mapping
 * there), the application's own attribute-mapped entity, zero bundle Doctrine configuration.
 */
final class StandardHostTestKernel extends AbstractHostTestKernel
{
    protected function variant(): string
    {
        return 'standard-host';
    }

    protected function doctrineConfig(): array
    {
        return [
            'dbal' => ['url' => 'sqlite:///:memory:'],
            'orm'  => [
                'auto_mapping' => true,
                'mappings'     => ['App' => [
                    'type'      => 'attribute',
                    'is_bundle' => false,
                    'dir'       => '%kernel.project_dir%/tests/Fixtures/Host/Entity',
                    'prefix'    => 'Nowo\\UptimeMonitorBundle\\Tests\\Fixtures\\Host\\Entity',
                ]],
            ],
        ];
    }

    protected function bundleDoctrineConfig(): array
    {
        return [];
    }
}
