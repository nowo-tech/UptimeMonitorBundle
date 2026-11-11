<?php

declare(strict_types=1);

namespace Nowo\UptimeMonitorBundle\Tests\Integration\BothHosts;

use Doctrine\Bundle\DoctrineBundle\DependencyInjection\Configuration as DoctrineConfiguration;
use Doctrine\Bundle\DoctrineBundle\DoctrineBundle;
use Nowo\FormKitBundle\NowoFormKitBundle;
use Nowo\UiKitBundle\NowoUiKitBundle;
use Nowo\UptimeMonitorBundle\Service\TenantProvisionerInterface;
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
 * Kernel of the both-host tests (1.27.0, REQ-ARCH-001): the same application, kept alive between requests (worker mode
 * without kernel.reset), with the Doctrine and bundle configuration of a standard or a DDD host.
 */
abstract class AbstractHostTestKernel extends Kernel
{
    use MicroKernelTrait;

    /** Public test alias of {@see TenantProvisionerInterface}. */
    public const TENANT_PORT = 'test.uptime.tenant_provisioner';

    public function __construct()
    {
        // No debug: the debug event dispatcher, stopwatch and query logger keep every request (they are reset only by
        // kernel.reset), which would hide the bundle's own memory profile.
        parent::__construct('test', false);
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
        return sys_get_temp_dir() . '/nowo-uptime-monitor-' . $this->variant() . '/cache';
    }

    public function getLogDir(): string
    {
        return sys_get_temp_dir() . '/nowo-uptime-monitor-' . $this->variant() . '/log';
    }

    /** Cache directory suffix. */
    abstract protected function variant(): string;

    /**
     * The `doctrine` extension configuration (without the lazy-object switch).
     *
     * @return array<string, mixed>
     */
    abstract protected function doctrineConfig(): array;

    /**
     * The `nowo_uptime_monitor.doctrine` configuration.
     *
     * @return array<string, mixed>
     */
    abstract protected function bundleDoctrineConfig(): array;

    private function configureContainer(ContainerConfigurator $container): void
    {
        $container->extension('framework', [
            'secret'               => 'both-hosts-test',
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
        $container->services()->set('logger', NullLogger::class);
        // The tenant port as a host service would receive it.
        $container->services()->alias(self::TENANT_PORT, TenantProvisionerInterface::class)->public();
        $doctrine        = $this->doctrineConfig();
        $doctrine['orm'] = (array) $doctrine['orm'] + $this->nativeLazyObjects();
        $container->extension('doctrine', $doctrine);
        $container->extension('security', [
            'firewalls' => ['main' => ['security' => false]],
        ]);
        $container->extension('nowo_uptime_monitor', [
            'security' => ['allow_unauthenticated' => true],
            'doctrine' => $this->bundleDoctrineConfig(),
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
