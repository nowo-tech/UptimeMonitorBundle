<?php

declare(strict_types=1);

namespace Nowo\UptimeMonitorBundle\Tests\Unit\Doctrine;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Events;
use Doctrine\ORM\Tools\SchemaTool;
use Doctrine\Persistence\ObjectManager;
use LogicException;
use Nowo\UptimeMonitorBundle\Doctrine\UptimeEntityManager;
use Nowo\UptimeMonitorBundle\Entity\Monitor;
use Nowo\UptimeMonitorBundle\Entity\Tenant;
use Nowo\UptimeMonitorBundle\Enum\MonitorType;
use Nowo\UptimeMonitorBundle\Tests\Support\RecordingLogger;
use Nowo\UptimeMonitorBundle\Tests\Support\SqliteManagerRegistry;
use Nowo\UptimeMonitorBundle\Tests\Support\StubManagerRegistry;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use RuntimeException;
use stdClass;
use Stringable;

use function dirname;

/**
 * The bundle's private entity manager (1.27.0, REQ-ARCH-001 rules 6 and 8, REQ-RUNTIME-002).
 */
#[CoversClass(UptimeEntityManager::class)]
final class UptimeEntityManagerTest extends TestCase
{
    private SqliteManagerRegistry $doctrine;

    protected function setUp(): void
    {
        $entities       = ['Nowo\\UptimeMonitorBundle\\Entity' => dirname(__DIR__, 3) . '/src/Entity'];
        $this->doctrine = new SqliteManagerRegistry(['default' => $entities, 'other' => $entities]);
        foreach (['default', 'other'] as $name) {
            $manager = $this->manager($name);
            (new SchemaTool($manager))->createSchema($manager->getMetadataFactory()->getAllMetadata());
        }
    }

    public function testThePrivateManagerSharesTheConnectionAndConfigurationOfTheMappingManager(): void
    {
        $bundle  = new UptimeEntityManager($this->doctrine);
        $default = $this->manager('default');

        self::assertFalse($bundle->isInitialized());
        $inner = $bundle->live();

        self::assertNotSame($default, $inner);
        self::assertSame($inner, $bundle->live(), 'kept while open');
        self::assertSame($default->getConnection(), $bundle->getConnection());
        self::assertSame($default->getConfiguration()->getMetadataDriverImpl(), $bundle->getConfiguration()->getMetadataDriverImpl(), 'same mapping, on a configuration copy');
        self::assertSame($default, $bundle->mappingManager());
        self::assertTrue($bundle->isInitialized());
    }

    public function testTheConfigurationSourceAndTheConnectionAreConfigurable(): void
    {
        $bundle = new UptimeEntityManager($this->doctrine, 'other', 'other');

        self::assertSame($this->manager('other')->getConfiguration()->getMetadataDriverImpl(), $bundle->getConfiguration()->getMetadataDriverImpl());
        self::assertSame($this->doctrine->getConnection('other'), $bundle->getConnection());
    }

    public function testAClosedInnerManagerIsReplacedOnTheNextCall(): void
    {
        $bundle = new UptimeEntityManager($this->doctrine);
        $first  = $bundle->live();
        $bundle->persist(new Tenant('kept', 'Kept'));
        $bundle->flush();

        $bundle->close();
        self::assertFalse($bundle->isOpen(), 'callers can tell their entities are detached');

        $tenant = $bundle->getRepository(Tenant::class)->findOneBy(['slug' => 'kept']);
        self::assertInstanceOf(Tenant::class, $tenant, 'the committed row survives');
        self::assertNotSame($first, $bundle->live());
        self::assertTrue($bundle->isOpen());
    }

    public function testResetDropsTheInnerManager(): void
    {
        $bundle = new UptimeEntityManager($this->doctrine);
        $bundle->reset();
        self::assertTrue($bundle->isOpen());

        $first  = $bundle->live();
        $tenant = new Tenant('acme', 'Acme');
        $bundle->persist($tenant);
        $bundle->flush();
        $bundle->reset();

        self::assertFalse($bundle->isInitialized());
        self::assertTrue($bundle->isOpen());
        self::assertFalse($first->contains($tenant), 'the dropped manager was cleared');
        self::assertNotSame($first, $bundle->live());
    }

    public function testOnlyTheWrappedPropertyIsVirtual(): void
    {
        $bundle = new UptimeEntityManager($this->doctrine);

        $this->expectException(LogicException::class);
        $bundle->__get('other');
    }

    public function testAdoptReturnsTheBundleCopy(): void
    {
        $bundle      = new UptimeEntityManager($this->doctrine);
        $application = $this->manager('default');
        $tenant      = new Tenant('acme', 'Acme');
        $application->persist($tenant);
        $application->flush();

        $copy = $bundle->adopt($tenant);
        self::assertNotSame($tenant, $copy);
        self::assertTrue($bundle->contains($copy));
        self::assertSame($copy, $bundle->adopt($copy), 'already managed here');

        $new = new Tenant('new', 'New');
        self::assertSame($new, $bundle->adopt($new), 'a new entity stays itself');

        $application->getConnection()->executeStatement('DELETE FROM uptime_tenant');
        $gone = new Tenant('gone', 'Gone');
        $application->persist($gone);
        $application->flush();
        $application->getConnection()->executeStatement('DELETE FROM uptime_tenant');
        self::assertSame($gone, $bundle->adopt($gone), 'no row: the entity itself');
    }

    public function testForApplicationReturnsTheApplicationCopy(): void
    {
        $bundle      = new UptimeEntityManager($this->doctrine);
        $application = $this->manager('default');
        $tenant      = new Tenant('acme', 'Acme');
        $bundle->persist($tenant);
        $bundle->flush();

        $copy = $bundle->forApplication($tenant);
        self::assertNotSame($tenant, $copy);
        self::assertTrue($application->contains($copy));
        self::assertSame($copy, $bundle->forApplication($copy), 'already the application\'s');

        $new = new Tenant('new', 'New');
        self::assertSame($new, $bundle->forApplication($new));
    }

    /**
     * 1.28.0: a boundary that drops pending bundle changes logs their entity classes and counts, never a value.
     */
    public function testResetWarnsAboutDroppedPendingChanges(): void
    {
        $logger = new RecordingLogger();
        $bundle = new UptimeEntityManager($this->doctrine, null, null, $logger);
        $tenant = new Tenant('acme', 'Acme');
        $bundle->persist($tenant);
        $bundle->flush();
        $tenant->setSettings(['secret' => 'renamed, never flushed']);
        $bundle->persist(new Tenant('new', 'New'));
        $bundle->persist(new Monitor($tenant, 'API', MonitorType::Https, 'https://api.test'));

        $bundle->reset();

        self::assertFalse($bundle->isInitialized());
        self::assertCount(1, $logger->records);
        self::assertSame('warning', $logger->records[0]['level']);
        self::assertSame(3, $logger->records[0]['context']['count']);
        self::assertSame([Monitor::class => 1, Tenant::class => 2], $logger->records[0]['context']['changes']);
        self::assertStringNotContainsString('renamed', (string) json_encode($logger->records));
        self::assertSame(0, (int) $this->manager('default')->getConnection()->fetchOne("SELECT COUNT(*) FROM uptime_tenant WHERE slug = 'new'"));

        $quiet  = new RecordingLogger();
        $bundle = new UptimeEntityManager($this->doctrine, null, null, $quiet);
        $bundle->live();
        $bundle->reset();
        self::assertSame([], $quiet->records, 'nothing pending: no warning');
    }

    /**
     * 1.28.0: a throwing `onClear` listener or a failing logger never keeps the manager across the boundary.
     */
    public function testResetNeverFailsTheBoundary(): void
    {
        $logger = new RecordingLogger();
        $bundle = new UptimeEntityManager($this->doctrine, null, null, $logger);
        $bundle->live();
        $listener = new class {
            public function onClear(): never
            {
                throw new RuntimeException('onClear listener failed');
            }
        };
        $events = $this->manager('default')->getEventManager();
        $events->addEventListener([Events::onClear], $listener);
        try {
            $bundle->reset();
        } finally {
            $events->removeEventListener([Events::onClear], $listener);
        }
        self::assertFalse($bundle->isInitialized());
        self::assertCount(1, $logger->records);
        self::assertSame(RuntimeException::class, $logger->records[0]['context']['exception']);

        $failing = new class extends AbstractLogger {
            /**
             * @param array<string, mixed> $context
             */
            public function log($level, string|Stringable $message, array $context = []): void
            {
                throw new RuntimeException('logger failed');
            }
        };
        $bundle = new UptimeEntityManager($this->doctrine, null, null, $failing);
        $bundle->persist(new Tenant('lost', 'Lost'));
        $bundle->reset();
        self::assertFalse($bundle->isInitialized(), 'a failing logger never keeps the manager');

        $bundle->persist(new Tenant('closed', 'Closed'));
        $bundle->close();
        $bundle->reset();
        self::assertFalse($bundle->isInitialized(), 'a closed manager is dropped without inspection');
    }

    public function testForApplicationKeepsARowTheApplicationCannotSee(): void
    {
        // The bundle tables live on the "other" connection; the mapping manager ("default") has no such row.
        $bundle = new UptimeEntityManager($this->doctrine, null, 'other');
        $tenant = new Tenant('elsewhere', 'Elsewhere');
        $bundle->persist($tenant);
        $bundle->flush();

        self::assertSame($tenant, $bundle->forApplication($tenant));
    }

    public function testForApplicationWithoutAnApplicationManagerForTheClass(): void
    {
        $source = $this->manager('other');
        $bundle = new UptimeEntityManager(new StubManagerRegistry(['other' => $source], ['other' => $source->getConnection()]), 'other', 'other');
        $tenant = new Tenant('acme', 'Acme');

        self::assertSame($tenant, $bundle->forApplication($tenant));
    }

    public function testNoManagerMapsTheBundleEntities(): void
    {
        $bundle = new UptimeEntityManager(new StubManagerRegistry());

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('No Doctrine ORM entity manager maps');
        $bundle->live();
    }

    public function testTheConfigurationSourceMustBeAnOrmManager(): void
    {
        $bundle = new UptimeEntityManager(new StubManagerRegistry(['odm' => $this->createStub(ObjectManager::class)]), 'odm');

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('"odm" (nowo_uptime_monitor.doctrine.entity_manager) is not a Doctrine ORM entity manager');
        $bundle->live();
    }

    public function testTheConnectionMustBeADbalConnection(): void
    {
        $bundle = new UptimeEntityManager(new StubManagerRegistry(['default' => $this->manager('default')], ['odd' => new stdClass()]), 'default', 'odd');

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('"odd" (nowo_uptime_monitor.doctrine.connection) is not a Doctrine DBAL connection');
        $bundle->live();
    }

    private function manager(string $name): EntityManagerInterface
    {
        $manager = $this->doctrine->getManager($name);
        self::assertInstanceOf(EntityManagerInterface::class, $manager);

        return $manager;
    }
}
