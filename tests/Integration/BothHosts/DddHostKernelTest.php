<?php

declare(strict_types=1);

namespace Nowo\UptimeMonitorBundle\Tests\Integration\BothHosts;

use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Doctrine\Persistence\ManagerRegistry;
use Nowo\UptimeMonitorBundle\Doctrine\UptimeEntityManager;
use Nowo\UptimeMonitorBundle\Entity\Monitor;
use Nowo\UptimeMonitorBundle\Service\TenantProvisionerInterface;
use Nowo\UptimeMonitorBundle\Tests\Fixtures\Host\Entity\HostNote;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\HttpKernel\TerminableInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * DDD host (1.27.0, REQ-ARCH-001 rules 2, 6 and 8): XML-only mapping, the application's default manager never maps
 * the bundle entities, which the application maps from the bundle's XML on a dedicated "uptime" manager. The bundle
 * tables, reads and writes follow that manager's connection; the application's default manager and connection are
 * never touched.
 */
final class DddHostKernelTest extends KernelTestCase
{
    private EntityManagerInterface $application;
    private EntityManagerInterface $uptime;

    protected static function getKernelClass(): string
    {
        return DddHostTestKernel::class;
    }

    protected function setUp(): void
    {
        self::bootKernel();
        $doctrine = self::getContainer()->get('doctrine');
        self::assertInstanceOf(ManagerRegistry::class, $doctrine);
        $application = $doctrine->getManager('default');
        $uptime      = $doctrine->getManager('uptime');
        self::assertInstanceOf(EntityManagerInterface::class, $application);
        self::assertInstanceOf(EntityManagerInterface::class, $uptime);
        $this->application = $application;
        $this->uptime      = $uptime;
        foreach ([$application, $uptime] as $manager) {
            (new SchemaTool($manager))->createSchema($manager->getMetadataFactory()->getAllMetadata());
        }
    }

    public function testTheBundleEntitiesLiveOnlyOnTheDedicatedManager(): void
    {
        $bundle = self::getContainer()->get(UptimeEntityManager::class);
        self::assertInstanceOf(UptimeEntityManager::class, $bundle);

        self::assertTrue($this->application->getMetadataFactory()->isTransient(Monitor::class), 'register_mapping: false');
        self::assertSame($this->uptime, $bundle->mappingManager());
        self::assertSame($this->uptime->getConnection(), $bundle->getConnection());
        self::assertFalse($this->tableExists($this->application->getConnection(), 'uptime_tenant'));
    }

    public function testThePortWritesOnTheDedicatedConnectionAndNeverTouchesTheApplication(): void
    {
        $note = new HostNote('pending');
        $this->application->persist($note);
        $provisioner = self::getContainer()->get(AbstractHostTestKernel::TENANT_PORT);
        self::assertInstanceOf(TenantProvisionerInterface::class, $provisioner);

        $tenant = $provisioner->ensure('acme', 'Acme');

        self::assertSame(1, (int) $this->uptime->getConnection()->fetchOne("SELECT COUNT(*) FROM uptime_tenant WHERE slug = 'acme'"));
        self::assertSame(0, (int) $this->application->getConnection()->fetchOne('SELECT COUNT(*) FROM host_note'));
        self::assertTrue($this->application->getUnitOfWork()->isScheduledForInsert($note));
        self::assertTrue($this->uptime->contains($tenant), 'the application manager that maps the bundle gets the tenant');
    }

    public function testTheBundlePagesRunOnTheDedicatedManager(): void
    {
        $provisioner = self::getContainer()->get(AbstractHostTestKernel::TENANT_PORT);
        self::assertInstanceOf(TenantProvisionerInterface::class, $provisioner);
        $provisioner->ensure('acme', 'Acme Uptime');
        $http = self::getContainer()->get('http_kernel');
        self::assertInstanceOf(HttpKernelInterface::class, $http);
        $router = self::getContainer()->get('router');
        self::assertInstanceOf(UrlGeneratorInterface::class, $router);

        $request  = Request::create($router->generate('nowo_uptime_tenant_index'));
        $response = $http->handle($request);
        if ($http instanceof TerminableInterface) {
            $http->terminate($request, $response);
        }

        self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());
        self::assertStringContainsString('Acme Uptime', (string) $response->getContent());
    }

    private function tableExists(Connection $connection, string $table): bool
    {
        return $connection->createSchemaManager()->tablesExist([$table]);
    }
}
