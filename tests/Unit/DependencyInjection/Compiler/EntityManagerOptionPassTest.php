<?php

declare(strict_types=1);

namespace Nowo\UptimeMonitorBundle\Tests\Unit\DependencyInjection\Compiler;

use Nowo\UptimeMonitorBundle\DependencyInjection\Compiler\EntityManagerOptionPass;
use Nowo\UptimeMonitorBundle\Doctrine\ExcludedEntityNamespaceDriver;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Reference;

use function array_map;

/**
 * Audit LD: the named entity manager must exist and map the bundle entities.
 */
final class EntityManagerOptionPassTest extends TestCase
{
    private const ENTITIES = 'Nowo\\UptimeMonitorBundle\\Entity';

    public function testNoOptionIsANoop(): void
    {
        $container = $this->container(null, ['default' => [self::ENTITIES]]);

        (new EntityManagerOptionPass())->process($container);

        self::assertCount(1, $container->getDefinition('doctrine.orm.default_metadata_driver')->getMethodCalls());
    }

    public function testUnknownManagerFailsTheBuild(): void
    {
        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage('no such Doctrine entity manager');

        (new EntityManagerOptionPass())->process($this->container('missing', ['default' => [self::ENTITIES]]));
    }

    public function testManagerThatDoesNotMapTheEntitiesFailsTheBuild(): void
    {
        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage('does not map');

        (new EntityManagerOptionPass())->process($this->container('bundle', ['default' => [self::ENTITIES], 'bundle' => ['App\\Entity']]));
    }

    public function testParentNamespaceMappingCounts(): void
    {
        $container = $this->container('bundle', ['default' => ['App\\Entity'], 'bundle' => ['Nowo']]);

        (new EntityManagerOptionPass())->process($container);

        self::assertCount(1, $container->getDefinition('doctrine.orm.bundle_metadata_driver')->getMethodCalls());
        self::assertCount(2, $container->getDefinition('doctrine.orm.default_metadata_driver')->getMethodCalls(), 'App\\Entity and the excluded driver');
    }

    public function testNamedManagerKeepsTheEntities(): void
    {
        $container = $this->container('bundle', ['default' => ['App\\Entity', self::ENTITIES], 'bundle' => [self::ENTITIES], 'other' => []]);
        $container->removeDefinition('doctrine.orm.other_metadata_driver');

        (new EntityManagerOptionPass())->process($container);

        self::assertCount(1, $container->getDefinition('doctrine.orm.bundle_metadata_driver')->getMethodCalls());
        self::assertCount(2, $container->getDefinition('doctrine.orm.default_metadata_driver')->getMethodCalls());
    }

    public function testOtherDriverCallsAreKept(): void
    {
        $container = $this->container('bundle', ['default' => [], 'bundle' => []]);
        $container->getDefinition('doctrine.orm.bundle_metadata_driver')
            ->addMethodCall('setDefaultDriver', [new Reference('driver')])
            ->addMethodCall('addDriver', [new Reference('driver'), self::ENTITIES]);

        (new EntityManagerOptionPass())->process($container);

        self::assertCount(2, $container->getDefinition('doctrine.orm.bundle_metadata_driver')->getMethodCalls());
        self::assertCount(1, $container->getDefinition('doctrine.orm.default_metadata_driver')->getMethodCalls(), 'the excluded driver');
    }

    /**
     * DoctrineBundle 3.4 `auto_discover_entities` gives a manager without mappings a default driver that claims every
     * entity class: a manager that does not map the bundle namespace gets a driver that maps nothing for it, first in
     * its chain; a manager that maps it (register_mapping, auto_mapping, a parent namespace) is left as is. Idempotent.
     */
    public function testManagersThatDoNotMapTheEntitiesGetAnExcludedDriverFirst(): void
    {
        $container = $this->container('bundle', ['default' => ['App\\Entity'], 'bundle' => [self::ENTITIES], 'mapped' => ['App\\Entity', self::ENTITIES], 'parent' => ['Nowo'], 'reports' => []]);
        $container->getDefinition('doctrine.orm.default_metadata_driver')->addMethodCall('setDefaultDriver', [new Reference('auto_discover')]);
        $container->getDefinition('doctrine.orm.mapped_metadata_driver')->addMethodCall('setDefaultDriver', [new Reference('auto_discover')]);

        for ($run = 0; $run < 2; ++$run) {
            (new EntityManagerOptionPass())->process($container);

            $calls = static fn (string $manager): array => array_map(
                static fn (array $call): array => [$call[0], $call[1][1] ?? null, $call[1][0] instanceof Definition ? $call[1][0]->getClass() : (string) $call[1][0]],
                $container->getDefinition('doctrine.orm.' . $manager . '_metadata_driver')->getMethodCalls(),
            );
            self::assertSame([
                ['addDriver', self::ENTITIES, ExcludedEntityNamespaceDriver::class],
                ['addDriver', 'App\\Entity', 'driver'],
                ['setDefaultDriver', null, 'auto_discover'],
            ], $calls('default'), 'run ' . $run);
            self::assertSame([['addDriver', self::ENTITIES, ExcludedEntityNamespaceDriver::class]], $calls('reports'), 'run ' . $run);
            self::assertSame([['addDriver', self::ENTITIES, 'driver']], $calls('bundle'), 'run ' . $run);
            self::assertSame([['addDriver', 'App\\Entity', 'driver'], ['addDriver', self::ENTITIES, 'driver'], ['setDefaultDriver', null, 'auto_discover']], $calls('mapped'), 'run ' . $run);
            self::assertSame([['addDriver', 'Nowo', 'driver']], $calls('parent'), 'run ' . $run);
        }
    }

    public function testKnownConnectionPasses(): void
    {
        $container = $this->container(null, ['default' => [self::ENTITIES]]);
        $container->setParameter(EntityManagerOptionPass::CONNECTION_PARAMETER, 'uptime');
        $container->setParameter('doctrine.connections', ['default' => 'doctrine.dbal.default_connection', 'uptime' => 'doctrine.dbal.uptime_connection']);

        (new EntityManagerOptionPass())->process($container);

        $this->addToAssertionCount(1);
    }

    public function testUnknownConnectionFailsTheBuild(): void
    {
        $container = $this->container(null, ['default' => [self::ENTITIES]]);
        $container->setParameter(EntityManagerOptionPass::CONNECTION_PARAMETER, 'missing');

        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage('nowo_uptime_monitor.doctrine.connection is "missing", but no such Doctrine connection is configured');

        (new EntityManagerOptionPass())->process($container);
    }

    /**
     * @param array<string, list<string>> $managers manager => mapped namespaces
     */
    private function container(?string $option, array $managers): ContainerBuilder
    {
        $container = new ContainerBuilder();
        $container->setParameter(EntityManagerOptionPass::PARAMETER, $option);
        $names = [];
        foreach ($managers as $manager => $namespaces) {
            $names[$manager] = 'doctrine.orm.' . $manager . '_entity_manager';
            $chain           = $container->register('doctrine.orm.' . $manager . '_metadata_driver');
            foreach ($namespaces as $namespace) {
                $chain->addMethodCall('addDriver', [new Reference('driver'), $namespace]);
            }
        }
        $container->setParameter('doctrine.entity_managers', $names);

        return $container;
    }
}
