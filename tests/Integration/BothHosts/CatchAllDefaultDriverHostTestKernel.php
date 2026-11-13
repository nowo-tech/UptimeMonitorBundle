<?php

declare(strict_types=1);

namespace Nowo\UptimeMonitorBundle\Tests\Integration\BothHosts;

use Doctrine\ORM\Mapping\Driver\AttributeDriver;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;

/**
 * {@see DefaultDriverHostTestKernel} plus a host compiler pass that gives the default manager's chain an explicit
 * catch-all `setDefaultDriver(AttributeDriver)`: what DoctrineBundle 3.4 `auto_discover_entities` does, on every
 * DoctrineBundle version.
 */
final class CatchAllDefaultDriverHostTestKernel extends DefaultDriverHostTestKernel
{
    protected function variant(): string
    {
        return 'catch-all-default-driver-host';
    }

    protected function build(ContainerBuilder $container): void
    {
        $container->addCompilerPass(new class implements CompilerPassInterface {
            public function process(ContainerBuilder $container): void
            {
                // An attribute driver answers "not transient" for any class carrying #[ORM\Entity], whatever its paths.
                $container->getDefinition('doctrine.orm.default_metadata_driver')
                    ->addMethodCall('setDefaultDriver', [new Definition(AttributeDriver::class, [[__DIR__ . '/../../Fixtures/Host/Entity'], true])]);
            }
        });
    }
}
