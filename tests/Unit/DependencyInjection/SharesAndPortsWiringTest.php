<?php

declare(strict_types=1);

namespace Nowo\UptimeMonitorBundle\Tests\Unit\DependencyInjection;

use LogicException;
use Nowo\UptimeMonitorBundle\DependencyInjection\Compiler\EntityManagerOptionPass;
use Nowo\UptimeMonitorBundle\DependencyInjection\UptimeMonitorExtension;
use Nowo\UptimeMonitorBundle\Doctrine\ExactUserIdentifierCollationListener;
use Nowo\UptimeMonitorBundle\Query\CheckAggregateQueryInterface;
use Nowo\UptimeMonitorBundle\Query\CheckAggregateTotals;
use Nowo\UptimeMonitorBundle\Query\CheckAggregateView;
use Nowo\UptimeMonitorBundle\Query\DoctrineCheckAggregateQuery;
use Nowo\UptimeMonitorBundle\Query\DoctrineIncidentQuery;
use Nowo\UptimeMonitorBundle\Query\DoctrineMonitorQuery;
use Nowo\UptimeMonitorBundle\Query\IncidentDowntime;
use Nowo\UptimeMonitorBundle\Query\IncidentDowntimeSql;
use Nowo\UptimeMonitorBundle\Query\IncidentQueryInterface;
use Nowo\UptimeMonitorBundle\Query\IncidentView;
use Nowo\UptimeMonitorBundle\Query\MonitorQueryInterface;
use Nowo\UptimeMonitorBundle\Query\MonitorScope;
use Nowo\UptimeMonitorBundle\Query\MonitorScopeFilter;
use Nowo\UptimeMonitorBundle\Query\ReadRow;
use Nowo\UptimeMonitorBundle\Repository\MonitorRepository;
use Nowo\UptimeMonitorBundle\Repository\MonitorRepositoryInterface;
use Nowo\UptimeMonitorBundle\Service\BulkTenantProvisionerInterface;
use Nowo\UptimeMonitorBundle\Service\TenantProvisioner;
use Nowo\UptimeMonitorBundle\Share\DenyAllMonitorSharePolicy;
use Nowo\UptimeMonitorBundle\Share\MonitorSharePolicyInterface;
use Nowo\UptimeMonitorBundle\Share\MonitorShares;
use Nowo\UptimeMonitorBundle\Share\MonitorSharesInterface;
use Nowo\UptimeMonitorBundle\Tests\Unit\Support\FakeDoctrineExtension;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use Symfony\Bundle\SecurityBundle\SecurityBundle;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Extension\Extension;
use Symfony\Component\DependencyInjection\Reference;

/**
 * 1.35 (Beacon spec 225): the opt-in shares and the new ports in the container.
 */
#[CoversClass(UptimeMonitorExtension::class)]
#[CoversClass(EntityManagerOptionPass::class)]
final class SharesAndPortsWiringTest extends TestCase
{
    private const ENTITIES = 'Nowo\\UptimeMonitorBundle\\Entity';
    private const SHARES   = 'Nowo\\UptimeMonitorBundle\\Share\\Entity';

    public function testTheNewPortsAreAliased(): void
    {
        $container = $this->container();
        (new UptimeMonitorExtension())->load([[]], $container);

        self::assertSame(DoctrineMonitorQuery::class, (string) $container->getAlias(MonitorQueryInterface::class));
        self::assertSame(TenantProvisioner::class, (string) $container->getAlias(BulkTenantProvisionerInterface::class));
        self::assertSame(MonitorRepository::class, (string) $container->getAlias(MonitorRepositoryInterface::class));
        // 1.36: the report ports.
        self::assertSame(DoctrineCheckAggregateQuery::class, (string) $container->getAlias(CheckAggregateQueryInterface::class));
        self::assertSame(DoctrineIncidentQuery::class, (string) $container->getAlias(IncidentQueryInterface::class));
        foreach ([CheckAggregateView::class, CheckAggregateTotals::class, IncidentView::class, IncidentDowntime::class, MonitorScope::class, MonitorScopeFilter::class, ReadRow::class, IncidentDowntimeSql::class] as $value) {
            self::assertTrue(!$container->hasDefinition($value) || $container->getDefinition($value)->hasTag('container.excluded'), $value . ' is not a service');
        }
    }

    public function testSharesAreOffByDefault(): void
    {
        $container = $this->container();
        (new UptimeMonitorExtension())->load([[]], $container);

        self::assertFalse($container->getParameter(EntityManagerOptionPass::SHARES_PARAMETER));
        self::assertFalse($container->has(MonitorSharesInterface::class));
        self::assertFalse($container->hasDefinition(ExactUserIdentifierCollationListener::class));
    }

    public function testEnabledSharesDenyByDefault(): void
    {
        $container = $this->container();
        (new UptimeMonitorExtension())->load([['shares' => ['enabled' => true]]], $container);

        self::assertTrue($container->getParameter(EntityManagerOptionPass::SHARES_PARAMETER));
        self::assertSame(MonitorShares::class, (string) $container->getAlias(MonitorSharesInterface::class));
        self::assertSame(DenyAllMonitorSharePolicy::class, (string) $container->getAlias(MonitorSharePolicyInterface::class));
        $policy = $container->getDefinition(MonitorShares::class)->getArgument('$policy');
        self::assertInstanceOf(Reference::class, $policy);
        self::assertSame(MonitorSharePolicyInterface::class, (string) $policy);
        self::assertSame([['event' => 'postGenerateSchemaTable']], $container->getDefinition(ExactUserIdentifierCollationListener::class)->getTag('doctrine.event_listener'));
    }

    public function testTheHostPolicyIsAliased(): void
    {
        $container = $this->container();
        (new UptimeMonitorExtension())->load([['shares' => ['enabled' => true, 'policy' => 'app.uptime_share_policy']]], $container);

        self::assertSame('app.uptime_share_policy', (string) $container->getAlias(MonitorSharePolicyInterface::class));
        self::assertFalse($container->hasDefinition(DenyAllMonitorSharePolicy::class));
    }

    public function testSharesEnabledMustBeALiteralBoolean(): void
    {
        // An env placeholder reaches load() as a string: the mapping was already decided at prepend time.
        $register = new ReflectionMethod(UptimeMonitorExtension::class, 'registerShares');

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('shares.enabled must be a literal boolean');
        $register->invoke(new UptimeMonitorExtension(), $this->container(), ['enabled' => 'env_0123_bool_UPTIME_SHARES', 'policy' => null]);
    }

    public function testThePrependMapsTheSharesOnlyWhenEnabled(): void
    {
        foreach (['attribute' => '/Share/Entity', 'xml' => '/Resources/config/doctrine-share'] as $type => $dir) {
            $container = new ContainerBuilder();
            $container->registerExtension(new FakeDoctrineExtension());
            $container->prependExtensionConfig('nowo_uptime_monitor', ['doctrine' => ['mapping_type' => $type], 'shares' => ['enabled' => true]]);
            (new UptimeMonitorExtension())->prepend($container);

            $mapping = $container->getExtensionConfig('doctrine')[0]['orm']['mappings'][UptimeMonitorExtension::SHARE_MAPPING];
            self::assertSame($type, $mapping['type']);
            self::assertFalse($mapping['is_bundle']);
            self::assertArrayNotHasKey('alias', $mapping, 'DoctrineBundle 3.4 deprecates the mapping alias option');
            self::assertSame(self::SHARES, $mapping['prefix']);
            self::assertStringEndsWith($dir, $mapping['dir']);
            self::assertDirectoryExists($mapping['dir']);
        }

        $off = new ContainerBuilder();
        $off->registerExtension(new FakeDoctrineExtension());
        (new UptimeMonitorExtension())->prepend($off);
        self::assertArrayNotHasKey(UptimeMonitorExtension::SHARE_MAPPING, $off->getExtensionConfig('doctrine')[0]['orm']['mappings']);
    }

    public function testTheEntityManagerOptionNeedsTheShareMappingWhenEnabled(): void
    {
        $container = $this->passContainer(['default' => ['App\\Entity'], 'bundle' => [self::ENTITIES, self::SHARES]]);
        (new EntityManagerOptionPass())->process($container);
        $calls = $container->getDefinition('doctrine.orm.default_metadata_driver')->getMethodCalls();
        self::assertSame([self::ENTITIES, self::SHARES], [$calls[0][1][1], $calls[1][1][1]], 'both namespaces excluded on the other manager');

        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage('does not map ' . self::SHARES);
        (new EntityManagerOptionPass())->process($this->passContainer(['default' => ['App\\Entity'], 'bundle' => [self::ENTITIES]]));
    }

    /**
     * @param array<string, list<string>> $managers
     */
    private function passContainer(array $managers): ContainerBuilder
    {
        $container = new ContainerBuilder();
        $container->setParameter(EntityManagerOptionPass::PARAMETER, 'bundle');
        $container->setParameter(EntityManagerOptionPass::SHARES_PARAMETER, true);
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

    private function container(): ContainerBuilder
    {
        $container = new ContainerBuilder();
        $container->registerExtension(new class extends Extension {
            public function load(array $configs, ContainerBuilder $container): void
            {
            }

            public function getAlias(): string
            {
                return 'security';
            }
        });
        $container->setParameter('kernel.bundles', ['SecurityBundle' => SecurityBundle::class]);

        return $container;
    }
}
