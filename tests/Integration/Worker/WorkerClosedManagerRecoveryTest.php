<?php

declare(strict_types=1);

namespace Nowo\UptimeMonitorBundle\Tests\Integration\Worker;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\HttpKernel\TerminableInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * FrankenPHP worker mode with the kernel kept alive and never reset (FRANKENPHP_RESET_KERNEL=false, no services
 * resetter): one booted kernel serves every request through the http_kernel service, so kernel.reset never runs.
 * A closed bundle manager is reset before the next bundle request, which then renders from the database; the host's
 * own closed manager ("reporting") is never touched by the bundle.
 */
final class WorkerClosedManagerRecoveryTest extends KernelTestCase
{
    protected static function getKernelClass(): string
    {
        return WorkerTestKernel::class;
    }

    public function testBundleManagerRecoversOnTheNextRequestAndHostManagerIsUntouched(): void
    {
        self::bootKernel();
        $container = self::getContainer();
        $doctrine  = $container->get('doctrine');
        self::assertInstanceOf(ManagerRegistry::class, $doctrine);
        $bundle = $this->manager($doctrine, 'default');
        $host   = $this->manager($doctrine, 'reporting');
        (new SchemaTool($bundle))->createSchema($bundle->getMetadataFactory()->getAllMetadata());
        $http = $container->get('http_kernel');
        self::assertInstanceOf(HttpKernelInterface::class, $http);
        $router = $container->get('router');
        self::assertInstanceOf(UrlGeneratorInterface::class, $router);
        $path = $router->generate('nowo_uptime_tenant_index');

        $host->close();
        for ($i = 0; $i < 20; ++$i) {
            // A failed flush of the previous request left the bundle manager closed.
            $bundle->close();
            $this->serve($http, $path);

            self::assertTrue($bundle->isOpen(), 'The bundle manager is reset before the next bundle request.');
            self::assertFalse($host->isOpen(), 'The bundle must never reset the host manager.');
        }
    }

    private function manager(ManagerRegistry $doctrine, string $name): EntityManagerInterface
    {
        $manager = $doctrine->getManager($name);
        self::assertInstanceOf(EntityManagerInterface::class, $manager);

        return $manager;
    }

    private function serve(HttpKernelInterface $http, string $path): void
    {
        $request  = Request::create($path);
        $response = $http->handle($request);
        self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());
        if ($http instanceof TerminableInterface) {
            $http->terminate($request, $response);
        }
    }
}
