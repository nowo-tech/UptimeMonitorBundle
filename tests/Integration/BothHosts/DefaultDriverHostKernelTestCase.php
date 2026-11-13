<?php

declare(strict_types=1);

namespace Nowo\UptimeMonitorBundle\Tests\Integration\BothHosts;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Doctrine\Persistence\ManagerRegistry;
use Nowo\UptimeMonitorBundle\Doctrine\UptimeEntityManager;
use Nowo\UptimeMonitorBundle\Entity\Monitor;
use Nowo\UptimeMonitorBundle\Entity\Tenant;
use Nowo\UptimeMonitorBundle\Service\TenantProvisionerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

use function array_keys;

/**
 * DoctrineBundle 3.4 regression: `auto_discover_entities` (on by default for a manager without `mappings` and
 * `auto_mapping`) gives the application's default manager a default attribute driver, which claims every
 * `#[ORM\Entity]` class. With `doctrine.entity_manager` naming the dedicated manager that maps the bundle entities,
 * `getManagerForClass()` answered the default manager (first in the registry): the bundle's private manager ran on the
 * default connection ("no such table") and the ports handed back entities of the wrong manager. The explicit catch-all
 * default driver reproduces it on every DoctrineBundle version ({@see CatchAllDefaultDriverHostKernelTest}).
 */
abstract class DefaultDriverHostKernelTestCase extends KernelTestCase
{
    public function testTheDedicatedManagerWinsOverADefaultDriverOfTheDefaultManager(): void
    {
        self::bootKernel();
        $doctrine = self::getContainer()->get('doctrine');
        self::assertInstanceOf(ManagerRegistry::class, $doctrine);
        self::assertSame(['default', 'uptime'], array_keys($doctrine->getManagerNames()));
        $uptime = $doctrine->getManager('uptime');
        self::assertInstanceOf(EntityManagerInterface::class, $uptime);

        // Neither manager is initialized yet: DoctrineBundle 3.4 asks the metadata drivers.
        self::assertSame($uptime, $doctrine->getManagerForClass(Monitor::class));
        self::assertSame($uptime, $doctrine->getManagerForClass(Tenant::class));

        $application = $doctrine->getManager('default');
        self::assertInstanceOf(EntityManagerInterface::class, $application);
        (new SchemaTool($uptime))->createSchema($uptime->getMetadataFactory()->getAllMetadata());
        self::assertTrue($application->getMetadataFactory()->isTransient(Monitor::class), 'not mapped on the default manager');
        self::assertSame($uptime, $doctrine->getManagerForClass(Monitor::class));

        // The private manager runs on the dedicated connection; a real write lands there and nowhere else.
        $bundle = self::getContainer()->get(UptimeEntityManager::class);
        self::assertInstanceOf(UptimeEntityManager::class, $bundle);
        self::assertSame($uptime, $bundle->mappingManager());
        self::assertSame($uptime->getConnection(), $bundle->getConnection());
        $provisioner = self::getContainer()->get(AbstractHostTestKernel::TENANT_PORT);
        self::assertInstanceOf(TenantProvisionerInterface::class, $provisioner);
        $tenant = $provisioner->ensure('acme', 'Acme');
        self::assertSame(1, (int) $uptime->getConnection()->fetchOne("SELECT COUNT(*) FROM uptime_tenant WHERE slug = 'acme'"));
        self::assertTrue($uptime->contains($tenant), 'the port hands back an entity of the manager that maps it');
        self::assertSame([], $application->getConnection()->createSchemaManager()->listTableNames(), 'nothing on the default connection');
    }
}
