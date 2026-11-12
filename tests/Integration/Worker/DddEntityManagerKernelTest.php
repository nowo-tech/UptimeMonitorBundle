<?php

declare(strict_types=1);

namespace Nowo\UptimeMonitorBundle\Tests\Integration\Worker;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Doctrine\Persistence\ManagerRegistry;
use Nowo\UptimeMonitorBundle\Doctrine\UptimeEntityManager;
use Nowo\UptimeMonitorBundle\Entity\Monitor;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\HttpKernel\TerminableInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Audit LD-UM-M1, kept-alive kernel (FRANKENPHP_RESET_KERNEL=false, no kernel.reset), with `doctrine.entity_manager`
 * naming a second manager. Since 1.27.0 the bundle's private manager shares that manager's configuration and the
 * connection of the manager that maps the bundle entities (the default one here, where the tables are: nothing moves);
 * the host's managers, closed by the host, are never reset and the bundle pages still render.
 */
final class DddEntityManagerKernelTest extends KernelTestCase
{
    protected static function getKernelClass(): string
    {
        return DddWorkerTestKernel::class;
    }

    public function testThePrivateManagerFollowsTheOptionAndNoHostManagerIsReset(): void
    {
        self::bootKernel();
        $container = self::getContainer();
        $doctrine  = $container->get('doctrine');
        self::assertInstanceOf(ManagerRegistry::class, $doctrine);
        $default   = $this->manager($doctrine, 'default');
        $option    = $this->manager($doctrine, 'bundle');
        $reporting = $this->manager($doctrine, 'reporting');
        self::assertSame($default, $doctrine->getManagerForClass(Monitor::class));
        (new SchemaTool($default))->createSchema($default->getMetadataFactory()->getAllMetadata());
        $bundle = $container->get(UptimeEntityManager::class);
        self::assertInstanceOf(UptimeEntityManager::class, $bundle);
        self::assertSame($option->getConfiguration()->getMetadataDriverImpl(), $bundle->getConfiguration()->getMetadataDriverImpl());
        self::assertSame($default->getConnection(), $bundle->getConnection(), 'the tables stay on the connection they are on');
        $http = $container->get('http_kernel');
        self::assertInstanceOf(HttpKernelInterface::class, $http);
        $router = $container->get('router');
        self::assertInstanceOf(UrlGeneratorInterface::class, $router);
        $path = $router->generate('nowo_uptime_tenant_index');

        $reporting->close();
        $option->close();
        $default->close();
        for ($i = 0; $i < 20; ++$i) {
            // A failed flush of the previous request left the bundle manager closed.
            $bundle->live()->close();
            $this->serve($http, $path);

            self::assertTrue($bundle->isOpen(), 'The bundle manager is replaced for the next bundle request.');
            self::assertFalse($default->isOpen(), 'The bundle must never reset a host manager.');
            self::assertFalse($option->isOpen(), 'The bundle must never reset a host manager.');
            self::assertFalse($reporting->isOpen(), 'The bundle must never reset a host manager.');
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
