<?php

declare(strict_types=1);

namespace Nowo\UptimeMonitorBundle\Tests\Integration\Service;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use Nowo\UptimeMonitorBundle\Entity\Monitor;
use Nowo\UptimeMonitorBundle\Entity\Tenant;
use Nowo\UptimeMonitorBundle\Enum\MonitorType;
use Nowo\UptimeMonitorBundle\Repository\MonitorRepository;
use Nowo\UptimeMonitorBundle\Repository\TenantRepository;
use Nowo\UptimeMonitorBundle\Service\MonitorBackupService;
use Nowo\UptimeMonitorBundle\Service\MonitorFactory;
use Nowo\UptimeMonitorBundle\Service\TenantProvisioner;
use Nowo\UptimeMonitorBundle\Tests\Support\SqliteEntityManagerFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * 1.35 (Beacon spec 225): the bulk tenant switch, the monitor lookup by tenant and the backup export by slug, on a
 * real schema (SQLite, or MySQL / MariaDB / PostgreSQL with UPTIME_TEST_DATABASE_URL).
 */
#[Group('db')]
#[CoversClass(TenantProvisioner::class)]
#[CoversClass(MonitorRepository::class)]
#[CoversClass(MonitorBackupService::class)]
final class TenantBulkAndLookupDbTest extends TestCase
{
    private EntityManagerInterface $em;
    private TenantRepository $tenants;
    private MonitorRepository $monitors;

    protected function setUp(): void
    {
        $this->em = SqliteEntityManagerFactory::create();
        $registry = $this->createStub(ManagerRegistry::class);
        $registry->method('getManagerForClass')->willReturn($this->em);
        $this->tenants  = new TenantRepository($registry);
        $this->monitors = new MonitorRepository($registry);
        foreach (['alpha', 'beta', 'gamma'] as $slug) {
            $this->em->persist(new Tenant($slug, ucfirst($slug)));
        }
        $this->em->flush();
    }

    public function testSetEnabledManyIsOneIdempotentStatement(): void
    {
        $provisioner = new TenantProvisioner($this->tenants, $this->em);
        $held        = $this->tenants->findOneBySlug('alpha');
        self::assertInstanceOf(Tenant::class, $held);

        $changed = $provisioner->setEnabledMany(['alpha', 'beta', 'ghost', 'Alpha', 'alpha'], false);

        self::assertGreaterThanOrEqual(2, $changed);
        self::assertSame(['alpha' => 0, 'beta' => 0, 'gamma' => 1], $this->states());
        self::assertTrue($this->em->contains($held), 'the managed copy stays managed (audit XZ-1)');
        self::assertFalse($held->isEnabled(), 'and holds the new state');
        self::assertFalse($this->tenants->findOneBySlug('alpha')?->isEnabled(), 'a later read sees the new state');

        $provisioner->setEnabledMany(['alpha', 'beta'], false);
        self::assertSame(['alpha' => 0, 'beta' => 0, 'gamma' => 1], $this->states(), 'running it again changes nothing');
        self::assertSame(0, $provisioner->setEnabledMany([], true));
        self::assertSame(0, $provisioner->setEnabledMany(['NOPE', ''], true), 'invalid slugs run no statement');

        $provisioner->setEnabledMany(['beta'], true);
        self::assertSame(['alpha' => 0, 'beta' => 1, 'gamma' => 1], $this->states());
    }

    public function testAManagedMonitorOfASwitchedTenantStillFlushes(): void
    {
        $alpha = $this->tenants->findOneBySlug('alpha');
        self::assertInstanceOf(Tenant::class, $alpha);
        $this->em->persist(new Monitor($alpha, 'API', MonitorType::Https, 'https://api.test'));
        $this->em->flush();
        $this->em->clear();
        $monitor = $this->monitors->findOneBy(['name' => 'API']);
        self::assertInstanceOf(Monitor::class, $monitor);
        $tenant = $monitor->getTenant();
        $tenant->setSettings(['theme' => 'dark']);
        self::assertTrue($tenant->isEnabled());

        (new TenantProvisioner($this->tenants, $this->em))->setEnabledMany(['alpha'], false);
        $monitor->setName('API v2');
        $this->em->flush();

        self::assertSame(['alpha' => 0, 'beta' => 1, 'gamma' => 1], $this->states());
        $connection = $this->em->getConnection();
        self::assertSame('API v2', $connection->fetchOne('SELECT name FROM uptime_monitor WHERE id = ?', [$monitor->getId()]));
        self::assertStringContainsString('dark', (string) $connection->fetchOne("SELECT settings FROM uptime_tenant WHERE slug = 'alpha'"), 'a pending change of the tenant is kept');
        self::assertFalse($tenant->isEnabled());

        // An uninitialised proxy of a switched tenant loads the new state itself.
        $this->em->clear();
        $proxy = $this->em->getReference(Tenant::class, $tenant->getId());
        (new TenantProvisioner($this->tenants, $this->em))->setEnabledMany(['alpha'], true);
        self::assertTrue($proxy?->isEnabled());
    }

    public function testFindInTenantNeedsTheExactTenant(): void
    {
        $alpha = $this->tenants->findOneBySlug('alpha');
        self::assertInstanceOf(Tenant::class, $alpha);
        $monitor = new Monitor($alpha, 'API', MonitorType::Https, 'https://api.test');
        $this->em->persist($monitor);
        $this->em->flush();
        $id = (int) $monitor->getId();

        self::assertSame($monitor, $this->monitors->findInTenant('alpha', $id));
        self::assertNull($this->monitors->findInTenant('beta', $id));
        self::assertNull($this->monitors->findInTenant('ALPHA', $id));
        self::assertNull($this->monitors->findInTenant('', $id));
        self::assertNull($this->monitors->findInTenant('alpha', 0));
        self::assertNull($this->monitors->findInTenant('alpha', $id + 100));
    }

    public function testTheBackupExportTakesASlug(): void
    {
        $alpha = $this->tenants->findOneBySlug('alpha');
        self::assertInstanceOf(Tenant::class, $alpha);
        $this->em->persist(new Monitor($alpha, 'API', MonitorType::Https, 'https://api.test'));
        $this->em->persist(new Monitor($alpha, 'Team', MonitorType::Group, 'Team'));
        $this->em->flush();
        $service = new MonitorBackupService($this->monitors, new MonitorFactory($this->monitors), $this->em);

        $bySlug   = $service->export('alpha');
        $byTenant = $service->export($alpha);

        self::assertSame('alpha', $bySlug['tenant']);
        self::assertSame(['API'], array_column($bySlug['monitors'], 'name'), 'groups left out');
        self::assertSame($byTenant['monitors'], $bySlug['monitors']);
        self::assertSame([], $service->export('ALPHA')['monitors'], 'the slug compares exactly');
        self::assertSame(['tenant' => 'ghost', 'monitors' => []], array_intersect_key($service->export('ghost'), ['tenant' => 1, 'monitors' => 1]));
    }

    /**
     * @return array<string, int>
     */
    private function states(): array
    {
        $states = [];
        foreach ($this->em->getConnection()->fetchAllAssociative('SELECT slug, enabled FROM uptime_tenant ORDER BY slug') as $row) {
            $states[(string) $row['slug']] = (int) $row['enabled'];
        }

        return $states;
    }
}
