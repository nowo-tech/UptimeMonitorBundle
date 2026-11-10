<?php

declare(strict_types=1);

namespace Nowo\UptimeMonitorBundle\Tests\Integration\Worker;

use Doctrine\Bundle\DoctrineBundle\DependencyInjection\Configuration as DoctrineConfiguration;
use Doctrine\Bundle\DoctrineBundle\DoctrineBundle;
use Nowo\FormKitBundle\NowoFormKitBundle;
use Nowo\UiKitBundle\NowoUiKitBundle;
use Nowo\UptimeMonitorBundle\UptimeMonitorBundle;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\FrameworkBundle;
use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;
use Symfony\Bundle\SecurityBundle\SecurityBundle;
use Symfony\Bundle\TwigBundle\TwigBundle;
use Symfony\Component\Config\Definition\ArrayNode;
use Symfony\Component\Config\Definition\BaseNode;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\HttpKernel\Kernel;
use Symfony\Component\Routing\Loader\Configurator\RoutingConfigurator;
use Twig\Extra\TwigExtraBundle\TwigExtraBundle;

use function dirname;
use function sys_get_temp_dir;

use const PHP_VERSION_ID;

/**
 * Audit LD-UM-M1 layout: `auto_mapping: true` on the host's default manager, a second manager ("bundle") onto which the
 * host also maps the bundle entities, `nowo_uptime_monitor.doctrine.entity_manager: bundle`, and a host-owned
 * "reporting" manager.
 */
final class DddWorkerTestKernel extends Kernel
{
    use MicroKernelTrait;

    public function __construct()
    {
        parent::__construct('test', true);
    }

    public function registerBundles(): iterable
    {
        yield new FrameworkBundle();
        yield new DoctrineBundle();
        yield new TwigBundle();
        yield new TwigExtraBundle();
        yield new SecurityBundle();
        yield new NowoUiKitBundle();
        yield new NowoFormKitBundle();
        yield new UptimeMonitorBundle();
    }

    public function getProjectDir(): string
    {
        return dirname(__DIR__, 3);
    }

    public function getCacheDir(): string
    {
        return sys_get_temp_dir() . '/nowo-uptime-monitor-ddd-worker/cache';
    }

    public function getLogDir(): string
    {
        return sys_get_temp_dir() . '/nowo-uptime-monitor-host-routing/log';
    }

    private function configureContainer(ContainerConfigurator $container): void
    {
        $container->extension('framework', [
            'secret'               => 'host-routing-test',
            'test'                 => true,
            'http_method_override' => false,
            'php_errors'           => ['log' => true],
            'csrf_protection'      => true,
            'form'                 => ['csrf_protection' => true],
            'session'              => ['storage_factory_id' => 'session.storage.factory.mock_file'],
            'property_info'        => ['with_constructor_extractor' => true],
            'assets'               => true,
            'validation'           => true,
            'translator'           => ['default_path' => '%kernel.project_dir%/tests/Fixtures/translations'],
        ]);
        $container->extension('twig', ['paths' => ['%kernel.project_dir%/tests/Fixtures/templates' => null]]);
        // No stderr logs from the default logger during the test run.
        $container->services()->set('logger', NullLogger::class);
        $container->extension('doctrine', [
            'dbal' => [
                'default_connection' => 'default',
                'connections'        => [
                    'default'   => ['url' => 'sqlite:///:memory:'],
                    'bundle'    => ['url' => 'sqlite:///:memory:'],
                    'reporting' => ['url' => 'sqlite:///:memory:'],
                ],
            ],
            'orm' => [
                'default_entity_manager' => 'default',
                'entity_managers'        => [
                    'default' => ['connection' => 'default', 'auto_mapping' => true],
                    'bundle'  => ['connection' => 'bundle', 'mappings' => ['UptimeEntities' => [
                        'type'      => 'attribute',
                        'is_bundle' => false,
                        'dir'       => '%kernel.project_dir%/src/Entity',
                        'prefix'    => 'Nowo\\UptimeMonitorBundle\\Entity',
                    ]]],
                    'reporting' => ['connection' => 'reporting'],
                ],
            ] + $this->nativeLazyObjects(),
        ]);
        $container->extension('security', [
            'firewalls' => ['main' => ['security' => false]],
        ]);
        $container->extension('nowo_uptime_monitor', [
            'security' => ['allow_unauthenticated' => true],
            'doctrine' => ['entity_manager' => 'bundle'],
        ]);
    }

    /**
     * ORM 3 on PHP 8.4+ needs native lazy objects: DoctrineBundle 2.x defaults `enable_native_lazy_objects` to false,
     * 3.x always uses them and deprecates the option.
     *
     * @return array<string, bool>
     */
    private function nativeLazyObjects(): array
    {
        if (PHP_VERSION_ID < 80400) {
            return [];
        }

        $root = (new DoctrineConfiguration(false))->getConfigTreeBuilder()->buildTree();
        $orm  = $root instanceof ArrayNode ? ($root->getChildren()['orm'] ?? null) : null;
        $node = $orm instanceof ArrayNode ? ($orm->getChildren()['enable_native_lazy_objects'] ?? null) : null;

        return $node instanceof BaseNode && !$node->isDeprecated() ? ['enable_native_lazy_objects' => true] : [];
    }

    private function configureRoutes(RoutingConfigurator $routes): void
    {
        $routes->import('@UptimeMonitorBundle/Resources/config/routes.yaml');
    }
}
