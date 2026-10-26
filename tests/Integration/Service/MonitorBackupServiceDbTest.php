<?php

declare(strict_types=1);

namespace Nowo\UptimeMonitorBundle\Tests\Integration\Service;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use InvalidArgumentException;
use Nowo\UptimeMonitorBundle\Entity\Monitor;
use Nowo\UptimeMonitorBundle\Entity\Tenant;
use Nowo\UptimeMonitorBundle\Enum\MonitorType;
use Nowo\UptimeMonitorBundle\Repository\MonitorRepository;
use Nowo\UptimeMonitorBundle\Service\MonitorBackupService;
use Nowo\UptimeMonitorBundle\Service\MonitorFactory;
use Nowo\UptimeMonitorBundle\Tests\Support\SqliteEntityManagerFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;

use function array_column;
use function array_map;

#[Group('db')]
#[CoversClass(MonitorBackupService::class)]
final class MonitorBackupServiceDbTest extends TestCase
{
    private EntityManagerInterface $em;
    private MonitorRepository $monitors;
    private MonitorBackupService $service;
    private Tenant $tenant;

    protected function setUp(): void
    {
        $this->em = SqliteEntityManagerFactory::create();
        $registry = $this->createStub(ManagerRegistry::class);
        $registry->method('getManagerForClass')->willReturn($this->em);

        $this->monitors = new MonitorRepository($registry);
        $this->service  = new MonitorBackupService(
            $this->monitors,
            new MonitorFactory($this->monitors),
            $this->em,
            null,
            new MockClock('2026-04-01 08:00:00 UTC'),
        );
        $this->tenant = new Tenant('main', 'Main');
        $this->em->persist($this->tenant);
    }

    public function testExportSkipsGroupsAndCarriesTheFormFields(): void
    {
        $this->em->persist(new Monitor($this->tenant, 'Group', MonitorType::Group, 'Group'));
        $api = (new Monitor($this->tenant, 'API', MonitorType::Https, 'https://api.test'))
            ->setConfig(['url' => 'https://api.test/health', 'method' => 'HEAD', 'expected_status_codes' => [200, 204]])
            ->setProject('Shop');
        $this->em->persist($api);
        $this->em->flush();

        $export = $this->service->export($this->tenant);

        self::assertSame(1, $export['version']);
        self::assertSame('2026-04-01T08:00:00+00:00', $export['exported_at']);
        self::assertSame('main', $export['tenant']);
        self::assertSame(['API'], array_column($export['monitors'], 'name'));
        self::assertSame('https://api.test/health', $export['monitors'][0]['form']['url']);
        self::assertSame('HEAD', $export['monitors'][0]['form']['method']);
        self::assertSame('200,204', $export['monitors'][0]['form']['expected_status_codes']);
        self::assertSame('Shop', $export['monitors'][0]['project']);
    }

    public function testImportCreatesSkipsAndOverwrites(): void
    {
        $existing = new Monitor($this->tenant, 'API', MonitorType::Https, 'https://old.test');
        $this->em->persist($existing);
        $this->em->flush();

        $payload = ['monitors' => [
            'not a row',
            ['name' => ''],
            ['type' => 'http'],
            [
                'name'   => 'API',
                'type'   => 'https',
                'target' => 'https://new.test',
                'form'   => ['url' => 'https://new.test/form', 'expected_status_codes' => '200-201'],
            ],
            [
                'name'                   => 'Shop',
                'type'                   => 'nope',
                'target'                 => 'https://shop.test',
                'interval_seconds'       => 120,
                'retries'                => 2,
                'retry_interval_seconds' => 45,
                'paused'                 => true,
                'project'                => 'Retail',
                'config'                 => ['method' => 'POST', 'expected_status_codes' => [200, 202]],
                'form'                   => 'ignored',
            ],
            ['name' => 'Bare', 'config' => 'ignored', 'target' => 'https://bare.test'],
        ]];

        self::assertSame(['imported' => 2, 'skipped' => 1, 'overwritten' => 0], $this->service->import($this->tenant, $payload));
        self::assertSame('https://old.test', $existing->getTarget(), 'skip mode keeps the existing monitor');

        $shop = $this->monitors->findOneBy(['name' => 'Shop']);
        self::assertInstanceOf(Monitor::class, $shop);
        self::assertSame(MonitorType::Https, $shop->getType());
        self::assertSame('https://shop.test', $shop->getTarget());
        self::assertSame(120, $shop->getIntervalSeconds());
        self::assertSame(2, $shop->getRetries());
        self::assertSame(45, $shop->getRetryIntervalSeconds());
        self::assertTrue($shop->isPaused());
        self::assertSame('Retail', $shop->getProject());
        self::assertSame('POST', $shop->getConfig()['method']);
        self::assertSame([200, 202], $shop->getConfig()['expected_status_codes']);

        self::assertSame(['imported' => 0, 'skipped' => 3, 'overwritten' => 0], $this->service->import($this->tenant, $payload, MonitorBackupService::IMPORT_KEEP));

        self::assertSame(['imported' => 0, 'skipped' => 0, 'overwritten' => 3], $this->service->import($this->tenant, $payload, MonitorBackupService::IMPORT_OVERWRITE));
        self::assertSame('https://new.test/form', $existing->getTarget());
        self::assertSame([200, 201], $existing->getConfig()['expected_status_codes']);

        self::assertSame(
            ['API', 'Bare', 'Shop'],
            array_map(static fn (Monitor $m): string => $m->getName(), $this->monitors->findByTenantSlug('main')),
        );
    }

    public function testImportRejectsAPayloadWithoutMonitors(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid backup: missing monitors array.');

        $this->service->import($this->tenant, ['monitors' => 'nope']);
    }
}
