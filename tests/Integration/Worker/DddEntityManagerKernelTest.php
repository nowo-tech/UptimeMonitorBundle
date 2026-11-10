<?php

declare(strict_types=1);

namespace Nowo\UptimeMonitorBundle\Tests\Integration\Worker;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Doctrine\Persistence\ManagerRegistry;
use Nowo\UptimeMonitorBundle\Entity\Monitor;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\HttpKernel\TerminableInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Audit LD-UM-M1, kept-alive kernel (FRANKENPHP_RESET_KERNEL=false, no kernel.reset): with
 * `doctrine.entity_manager` naming a second manager, the manager the bundle entities are mapped on and written through
 * (getManagerForClass(Monitor), the default one here) is still reset before the next bundle request; the host's
 * closed "reporting" manager is never touched.
 */
final class DddEntityManagerKernelTest extends KernelTestCase
{
    protected static function getKernelClass(): string
    {
        return DddWorkerTestKernel::class;
    }

    public function testManagerOfTheEntitiesRecoversWithTheOptionSet(): void
    {
        self::bootKernel();
        $container = self::getContainer();
        $doctrine  = $container->get('doctrine');
        self::assertInstanceOf(ManagerRegistry::class, $doctrine);
        $default   = $this->manager($doctrine, 'default');
        $reporting = $this->manager($doctrine, 'reporting');
        self::assertSame($default, $doctrine->getManagerForClass(Monitor::class));
        (new SchemaTool($default))->createSchema($default->getMetadataFactory()->getAllMetadata());
        $http = $container->get('http_kernel');
        self::assertInstanceOf(HttpKernelInterface::class, $http);
        $router = $container->get('router');
        self::assertInstanceOf(UrlGeneratorInterface::class, $router);
        $path = $router->generate('nowo_uptime_tenant_index');

        $reporting->close();
        for ($i = 0; $i < 20; ++$i) {
            // A failed flush of the previous request left the manager of the bundle entities closed.
            $default->close();
            $this->serve($http, $path);

            self::assertTrue($default->isOpen(), 'The manager the bundle entities are written through is reset.');
            self::assertFalse($reporting->isOpen(), 'The bundle must never reset the host manager.');
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
