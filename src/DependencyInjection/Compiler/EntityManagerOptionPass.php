<?php

declare(strict_types=1);

namespace Nowo\UptimeMonitorBundle\DependencyInjection\Compiler;

use Nowo\UptimeMonitorBundle\Doctrine\ExcludedEntityNamespaceDriver;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;

use function array_keys;
use function array_merge;
use function implode;
use function is_array;
use function is_string;
use function sprintf;
use function str_starts_with;

/**
 * Validates `nowo_uptime_monitor.doctrine.entity_manager` (1.26.0, audit LD) and `doctrine.connection` (1.27.0).
 *
 * The build fails when the configured entity manager does not exist or does not map the bundle entities, or when the
 * configured connection does not exist.
 *
 * With the option set, every other manager whose chain does not map the bundle namespace itself gets an
 * {@see ExcludedEntityNamespaceDriver} for it, first in its chain: a default driver of that chain (DoctrineBundle 3.4
 * `auto_discover_entities`, on by default for a manager without `mappings` and `auto_mapping`) then cannot claim the
 * bundle entities, so `getManagerForClass()` keeps answering a manager that maps them (where the tables are). A manager
 * that maps the namespace (the bundle's `register_mapping`, `auto_mapping`, a host mapping) is left as is. Only the
 * public `addDriver()` of the documented `doctrine.orm.<manager>_metadata_driver` chain is used.
 */
final class EntityManagerOptionPass implements CompilerPassInterface
{
    public const PARAMETER = 'nowo_uptime_monitor.doctrine.entity_manager';

    public const CONNECTION_PARAMETER = 'nowo_uptime_monitor.doctrine.connection';

    /** @var list<string> */
    private const ENTITY_NAMESPACES = ['Nowo\\UptimeMonitorBundle\\Entity'];

    /**
     * Fails the build when the configured entity manager does not exist or does not map the bundle entities.
     */
    public function process(ContainerBuilder $container): void
    {
        $this->validateConnection($container);

        $name = $container->hasParameter(self::PARAMETER) ? $container->getParameter(self::PARAMETER) : null;
        if (!is_string($name) || $name === '') {
            return;
        }

        $managers = $container->hasParameter('doctrine.entity_managers') ? $container->getParameter('doctrine.entity_managers') : [];
        $managers = is_array($managers) ? $managers : [];
        if (!isset($managers[$name])) {
            throw new InvalidConfigurationException(sprintf('nowo_uptime_monitor.doctrine.entity_manager is "%s", but no such Doctrine entity manager is configured (known: "%s"). Add it under doctrine.orm.entity_managers or remove the option.', $name, implode('", "', array_keys($managers))));
        }

        foreach (array_keys($managers) as $manager) {
            $chainId = sprintf('doctrine.orm.%s_metadata_driver', $manager);
            if (!$container->hasDefinition($chainId)) {
                continue;
            }

            $chain = $container->getDefinition($chainId);
            $calls = [];
            $maps  = false;
            foreach ($chain->getMethodCalls() as $call) {
                if ($this->isExcludedDriver($call)) {
                    continue;
                }
                $namespace = $call[0] === 'addDriver' ? ($call[1][1] ?? null) : null;
                $maps      = $maps || $this->isBundleNamespace($namespace, true);
                $calls[]   = $call;
            }
            if ($manager !== $name && !$maps) {
                // First in the chain: a chain asks its namespace drivers in order, then its default driver.
                $excluded = [];
                foreach (self::ENTITY_NAMESPACES as $bundleNamespace) {
                    $excluded[] = ['addDriver', [new Definition(ExcludedEntityNamespaceDriver::class), $bundleNamespace]];
                }
                $calls = array_merge($excluded, $calls);
            }
            $chain->setMethodCalls($calls);

            if ($manager === $name && !$maps) {
                throw new InvalidConfigurationException(sprintf('The Doctrine entity manager "%s" (nowo_uptime_monitor.doctrine.entity_manager) does not map %s.', $name, implode(', ', self::ENTITY_NAMESPACES)));
            }
        }
    }

    /**
     * Since 1.27.0: fails the build when `doctrine.connection` names no configured DBAL connection.
     */
    private function validateConnection(ContainerBuilder $container): void
    {
        $name = $container->hasParameter(self::CONNECTION_PARAMETER) ? $container->getParameter(self::CONNECTION_PARAMETER) : null;
        if (!is_string($name) || $name === '') {
            return;
        }

        $connections = $container->hasParameter('doctrine.connections') ? $container->getParameter('doctrine.connections') : [];
        $connections = is_array($connections) ? $connections : [];
        if (!isset($connections[$name])) {
            throw new InvalidConfigurationException(sprintf('nowo_uptime_monitor.doctrine.connection is "%s", but no such Doctrine connection is configured (known: "%s"). Add it under doctrine.dbal.connections or remove the option.', $name, implode('", "', array_keys($connections))));
        }
    }

    /**
     * Whether a method call registers the {@see ExcludedEntityNamespaceDriver} of an earlier run (idempotency).
     *
     * @param array{0: string, 1?: array<mixed>} $call
     */
    private function isExcludedDriver(array $call): bool
    {
        $driver = $call[0] === 'addDriver' ? ($call[1][0] ?? null) : null;

        return $driver instanceof Definition && $driver->getClass() === ExcludedEntityNamespaceDriver::class;
    }

    /**
     * @param bool $orParent also accept a parent namespace of the bundle entities (a host mapping of a wider namespace)
     */
    private function isBundleNamespace(mixed $namespace, bool $orParent): bool
    {
        if (!is_string($namespace)) {
            return false;
        }
        foreach (self::ENTITY_NAMESPACES as $bundleNamespace) {
            if (str_starts_with($namespace, $bundleNamespace) || ($orParent && str_starts_with($bundleNamespace, $namespace . '\\'))) {
                return true;
            }
        }

        return false;
    }
}
