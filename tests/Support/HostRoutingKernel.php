<?php

declare(strict_types=1);

namespace Nowo\UptimeMonitorBundle\Tests\Support;

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
use function md5;
use function serialize;
use function sys_get_temp_dir;

use const PHP_VERSION_ID;

/**
 * Host kernel for the routing tests (REQ-SF-004 rule 7): its routing is the Symfony default
 * `controllers: { resource: routing.controllers }` and, when `$importBundleRoutes` is true, the documented import of
 * the bundle routes (`@UptimeMonitorBundle/Resources/config/routes.yaml`).
 */
final class HostRoutingKernel extends Kernel
{
    use MicroKernelTrait;

    /**
     * @param array<string, mixed> $webUi `nowo_uptime_monitor.web_ui` of this host
     * @param array<string, mixed> $security `nowo_uptime_monitor.security` of this host; empty = allow_unauthenticated
     */
    public function __construct(private readonly bool $importBundleRoutes, private readonly array $webUi = [], private readonly array $security = [])
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
        return dirname(__DIR__, 2);
    }

    public function getCacheDir(): string
    {
        return sys_get_temp_dir() . '/nowo-uptime-monitor-host-routing/' . ($this->importBundleRoutes ? 'imported' : 'controllers-only') . '/' . md5(serialize([$this->webUi, $this->security])) . '/cache';
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
            'dbal' => ['url' => 'sqlite:///:memory:'],
            'orm'  => ['auto_mapping' => true] + $this->nativeLazyObjects(),
        ]);
        $container->extension('security', [
            'firewalls' => ['main' => ['security' => false]],
        ]);
        if ($this->security !== []) {
            $container->services()->set('test.shared_monitor_access_checker', SharedMonitorAccessChecker::class);
        }
        $container->extension('nowo_uptime_monitor', [
            'security' => $this->security === [] ? ['allow_unauthenticated' => true] : $this->security,
            'web_ui'   => $this->webUi,
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
        $routes->import('routing.controllers');
        if ($this->importBundleRoutes) {
            $routes->import('@UptimeMonitorBundle/Resources/config/routes.yaml');
        }
    }
}
