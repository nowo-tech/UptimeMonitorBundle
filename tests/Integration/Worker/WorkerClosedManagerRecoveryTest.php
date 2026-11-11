<?php

declare(strict_types=1);

namespace Nowo\UptimeMonitorBundle\Tests\Integration\Worker;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Doctrine\Persistence\ManagerRegistry;
use Nowo\UptimeMonitorBundle\Doctrine\UptimeEntityManager;
use Nowo\UptimeMonitorBundle\Entity\Tenant;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\HttpKernel\TerminableInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * FrankenPHP worker mode with the kernel kept alive and never reset (FRANKENPHP_RESET_KERNEL=false, no services
 * resetter): one booted kernel serves every request through the http_kernel service, so kernel.reset never runs.
 * Since 1.27.0 the bundle works on its private manager: a failed bundle flush closes only that manager, which the next
 * request replaces; the host's managers ("default", which maps the bundle entities, and "reporting"), closed by the
 * host, are never reset by the bundle, and the bundle pages still render from the database.
 */
final class WorkerClosedManagerRecoveryTest extends KernelTestCase
{
    protected static function getKernelClass(): string
    {
        return WorkerTestKernel::class;
    }

    public function testBundleManagerRecoversOnTheNextRequestAndHostManagersAreUntouched(): void
    {
        self::bootKernel();
        $container = self::getContainer();
        $doctrine  = $container->get('doctrine');
        self::assertInstanceOf(ManagerRegistry::class, $doctrine);
        $default = $this->manager($doctrine, 'default');
        $host    = $this->manager($doctrine, 'reporting');
        (new SchemaTool($default))->createSchema($default->getMetadataFactory()->getAllMetadata());
        $default->persist(new Tenant('acme', 'Acme Worker'));
        $default->flush();
        $bundle = $container->get(UptimeEntityManager::class);
        self::assertInstanceOf(UptimeEntityManager::class, $bundle);
        $http = $container->get('http_kernel');
        self::assertInstanceOf(HttpKernelInterface::class, $http);
        $router = $container->get('router');
        self::assertInstanceOf(UrlGeneratorInterface::class, $router);
        $path = $router->generate('nowo_uptime_tenant_index');

        $host->close();
        $default->close();
        for ($i = 0; $i < 20; ++$i) {
            // A failed flush of the previous request left the bundle manager closed.
            $bundle->live()->close();
            self::assertFalse($bundle->isOpen());

            self::assertStringContainsString('Acme Worker', $this->serve($http, $path));

            self::assertTrue($bundle->isOpen(), 'The bundle manager is replaced for the next bundle request.');
            self::assertFalse($default->isOpen(), 'The bundle must never reset the host manager that maps its entities.');
            self::assertFalse($host->isOpen(), 'The bundle must never reset a host manager.');
        }
    }

    private function manager(ManagerRegistry $doctrine, string $name): EntityManagerInterface
    {
        $manager = $doctrine->getManager($name);
        self::assertInstanceOf(EntityManagerInterface::class, $manager);

        return $manager;
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
}
