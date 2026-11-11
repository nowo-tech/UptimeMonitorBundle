<?php

declare(strict_types=1);

namespace Nowo\UptimeMonitorBundle\Tests\Unit\Doctrine;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use Doctrine\ORM\Tools\SchemaTool;
use Nowo\UptimeMonitorBundle\Doctrine\UptimeEntityManager;
use Nowo\UptimeMonitorBundle\Doctrine\UptimeManagerRegistry;
use Nowo\UptimeMonitorBundle\Entity\Monitor;
use Nowo\UptimeMonitorBundle\Entity\Tenant;
use Nowo\UptimeMonitorBundle\Tests\Fixtures\Host\Entity\HostNote;
use Nowo\UptimeMonitorBundle\Tests\Support\SqliteManagerRegistry;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

use function dirname;

/**
 * The bundle's view of the registry (1.27.0): bundle classes → the private manager; everything else → the
 * application registry; an application manager is never reset through it.
 */
#[CoversClass(UptimeManagerRegistry::class)]
final class UptimeManagerRegistryTest extends TestCase
{
    public function testBundleClassesGoToThePrivateManagerAndTheRestToTheApplication(): void
    {
        $root     = dirname(__DIR__, 3);
        $doctrine = new SqliteManagerRegistry([
            'default'   => ['Nowo\\UptimeMonitorBundle\\Entity' => $root . '/src/Entity'],
            'reporting' => ['Nowo\\UptimeMonitorBundle\\Tests\\Fixtures\\Host\\Entity' => $root . '/tests/Fixtures/Host/Entity'],
        ]);
        $default = $doctrine->getManager();
        self::assertInstanceOf(EntityManagerInterface::class, $default);
        (new SchemaTool($default))->createSchema($default->getMetadataFactory()->getAllMetadata());
        $bundle   = new UptimeEntityManager($doctrine);
        $registry = new UptimeManagerRegistry($doctrine, $bundle);

        self::assertSame($bundle, $registry->getManagerForClass(Monitor::class));
        // A Doctrine proxy class name of a bundle entity.
        self::assertSame($bundle, $registry->getManagerForClass('Proxies\\__CG__\\' . Monitor::class)); // @phpstan-ignore argument.type
        self::assertSame($doctrine->getManager('reporting'), $registry->getManagerForClass(HostNote::class));
        self::assertSame($bundle, $registry->getManager(UptimeEntityManager::NAME));
        self::assertSame($default, $registry->getManager());
        self::assertSame($bundle, $registry->getManagers()[UptimeEntityManager::NAME]);
        self::assertSame($default, $registry->getManagers()['default']);
        self::assertSame(UptimeEntityManager::class, $registry->getManagerNames()[UptimeEntityManager::NAME]);
        self::assertArrayHasKey('reporting', $registry->getManagerNames());
        self::assertSame('default', $registry->getDefaultManagerName());
        self::assertSame('default', $registry->getDefaultConnectionName());
        self::assertSame($doctrine->getConnection('reporting'), $registry->getConnection('reporting'));
        self::assertSame($doctrine->getConnections(), $registry->getConnections());
        self::assertSame($doctrine->getConnectionNames(), $registry->getConnectionNames());

        $repository = $registry->getRepository(Tenant::class);
        self::assertInstanceOf(EntityRepository::class, $repository);
        $tenant = new Tenant('acme', 'Acme');
        $bundle->persist($tenant);
        $bundle->flush();
        self::assertSame($tenant, $repository->findOneBy(['slug' => 'acme']), 'read through the private manager');
        self::assertSame($tenant, $registry->getRepository(Tenant::class, UptimeEntityManager::NAME)->findOneBy(['slug' => 'acme']));
        self::assertNotSame($tenant, $registry->getRepository(Tenant::class, 'default')->findOneBy(['slug' => 'acme']));
        self::assertInstanceOf(EntityRepository::class, $registry->getRepository(HostNote::class));

        $default->close();
        self::assertSame($default, $registry->resetManager('default'), 'never reset through the bundle registry');
        self::assertFalse($default->isOpen());

        $bundle->close();
        self::assertSame($bundle, $registry->resetManager(UptimeEntityManager::NAME));
        self::assertTrue($bundle->isOpen());
        self::assertFalse($bundle->isInitialized());
    }
}
