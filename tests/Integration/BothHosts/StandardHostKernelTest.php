<?php

declare(strict_types=1);

namespace Nowo\UptimeMonitorBundle\Tests\Integration\BothHosts;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Doctrine\Persistence\ManagerRegistry;
use Nowo\UptimeMonitorBundle\Doctrine\UptimeEntityManager;
use Nowo\UptimeMonitorBundle\Entity\Monitor;
use Nowo\UptimeMonitorBundle\Entity\Tenant;
use Nowo\UptimeMonitorBundle\Enum\MonitorType;
use Nowo\UptimeMonitorBundle\EventSubscriber\WorkerBoundaryResetListener;
use Nowo\UptimeMonitorBundle\Repository\TenantRepository;
use Nowo\UptimeMonitorBundle\Service\TenantProvisionerInterface;
use Nowo\UptimeMonitorBundle\Tests\Fixtures\Host\Entity\HostNote;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\ConsoleEvents;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\HttpKernel\TerminableInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

use function gc_collect_cycles;
use function is_array;
use function memory_get_usage;
use function sprintf;

/**
 * Standard host, zero bundle Doctrine configuration (1.27.0, REQ-ARCH-001 rule 6, REQ-RUNTIME-002): the bundle works on
 * its private manager over the application's connection; the application manager is never flushed or reset by it; the
 * kept-alive kernel drops the private manager at every boundary, so no request sees the previous one's entities.
 */
final class StandardHostKernelTest extends KernelTestCase
{
    private EntityManagerInterface $application;

    protected static function getKernelClass(): string
    {
        return StandardHostTestKernel::class;
    }

    protected function setUp(): void
    {
        self::bootKernel();
        $doctrine = self::getContainer()->get('doctrine');
        self::assertInstanceOf(ManagerRegistry::class, $doctrine);
        $application = $doctrine->getManager();
        self::assertInstanceOf(EntityManagerInterface::class, $application);
        $this->application = $application;
        (new SchemaTool($application))->createSchema($application->getMetadataFactory()->getAllMetadata());
    }

    public function testTheBundleWorksOnItsPrivateManagerOverTheApplicationConnection(): void
    {
        $bundle = $this->bundle();

        self::assertSame($this->application, $bundle->mappingManager(), 'zero-config: the default manager maps the bundle');
        self::assertNotSame($this->application, $bundle->live());
        self::assertSame($this->application->getConnection(), $bundle->getConnection(), 'the tables stay where they are');
    }

    public function testTheTenantPortNeverFlushesTheApplicationsPendingChanges(): void
    {
        $note = new HostNote('pending');
        $this->application->persist($note);
        $provisioner = self::getContainer()->get(AbstractHostTestKernel::TENANT_PORT);
        self::assertInstanceOf(TenantProvisionerInterface::class, $provisioner);

        $tenant = $provisioner->ensure('acme', 'Acme');
        $provisioner->setEnabled('acme', false);

        self::assertSame(0, $this->rows('host_note'));
        self::assertTrue($this->application->getUnitOfWork()->isScheduledForInsert($note));
        self::assertSame(1, $this->rows("uptime_tenant WHERE slug = 'acme' AND enabled = 0"));
        self::assertTrue($this->application->contains($tenant), 'the port hands back the application\'s tenant');

        // The application relates its own work to the tenant and flushes its own manager.
        $this->application->persist(new Monitor($tenant, 'API', MonitorType::Https, 'https://api.test'));
        $this->application->flush();
        self::assertSame(1, $this->rows('host_note'));
        self::assertSame(1, $this->rows('uptime_monitor'));
    }

    public function testTheRepositoryServicesStayOnTheApplicationManager(): void
    {
        $this->application->persist(new Tenant('acme', 'Acme'));
        $this->application->flush();
        $this->application->clear();
        $tenants = self::getContainer()->get(TenantRepository::class);
        self::assertInstanceOf(TenantRepository::class, $tenants);

        $tenant = $tenants->findOneBySlug('acme');

        self::assertInstanceOf(Tenant::class, $tenant);
        self::assertTrue($this->application->contains($tenant), 'unchanged for applications that relate them to their entities');
    }

    public function testTheKeptAliveKernelDropsThePrivateManagerAtEveryBoundary(): void
    {
        $container = self::getContainer();
        $http      = $container->get('http_kernel');
        self::assertInstanceOf(HttpKernelInterface::class, $http);
        $router = $container->get('router');
        self::assertInstanceOf(UrlGeneratorInterface::class, $router);
        $dispatcher = $container->get('event_dispatcher');
        self::assertInstanceOf(EventDispatcherInterface::class, $dispatcher);
        $provisioner = $container->get(AbstractHostTestKernel::TENANT_PORT);
        self::assertInstanceOf(TenantProvisionerInterface::class, $provisioner);
        $bundle = $this->bundle();
        $path   = $router->generate('nowo_uptime_tenant_index');
        $provisioner->ensure('alpha', 'Alpha');
        $provisioner->ensure('beta', 'Beta');
        $this->application->persist($note = new HostNote('pending for the whole run'));

        $baseline = 0;
        for ($i = 0; $i < 1000; ++$i) {
            // Another process renames the tenants between two requests: no request may serve a stale name.
            $slug = $i % 2 === 0 ? 'alpha' : 'beta';
            $name = sprintf('%s %d', $slug, $i);
            $this->application->getConnection()->executeStatement('UPDATE uptime_tenant SET name = ? WHERE slug = ?', [$name, $slug]);

            $content = $this->serve($http, $path);

            self::assertStringContainsString($name, $content, 'request ' . $i . ' read fresh rows');
            self::assertFalse($bundle->isInitialized(), 'kernel.terminate dropped the private manager');
            if ($i === 50) {
                gc_collect_cycles();
                $baseline = memory_get_usage();
            }
        }
        gc_collect_cycles();
        self::assertLessThan(8 * 1024 * 1024, memory_get_usage() - $baseline, 'no per-request growth');

        foreach ([ConsoleEvents::COMMAND, ConsoleEvents::TERMINATE, WorkerBoundaryResetListener::MESSENGER_RECEIVED, WorkerBoundaryResetListener::MESSENGER_HANDLED, WorkerBoundaryResetListener::MESSENGER_FAILED] as $boundary) {
            $bundle->live();
            $called = 0;
            foreach ($dispatcher->getListeners($boundary) as $listener) {
                if (is_array($listener) && $listener[0] instanceof WorkerBoundaryResetListener) {
                    $listener[0]->{$listener[1]}();
                    ++$called;
                }
            }
            self::assertSame(1, $called, $boundary . ' is a boundary');
            self::assertFalse($bundle->isInitialized(), $boundary . ' dropped the private manager');
        }

        self::assertSame(0, $this->rows('host_note'), 'the application manager was never flushed');
        self::assertTrue($this->application->getUnitOfWork()->isScheduledForInsert($note));
    }

    private function bundle(): UptimeEntityManager
    {
        $bundle = self::getContainer()->get(UptimeEntityManager::class);
        self::assertInstanceOf(UptimeEntityManager::class, $bundle);

        return $bundle;
    }

    private function serve(HttpKernelInterface $http, string $path): string
    {
        $request  = Request::create($path);
        $response = $http->handle($request);
        self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());
        if ($http instanceof TerminableInterface) {
            $http->terminate($request, $response);
        }

        return (string) $response->getContent();
    }

    private function rows(string $from): int
    {
        return (int) $this->application->getConnection()->fetchOne('SELECT COUNT(*) FROM ' . $from);
    }
}
