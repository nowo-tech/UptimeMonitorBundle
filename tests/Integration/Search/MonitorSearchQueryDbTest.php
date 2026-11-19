<?php

declare(strict_types=1);

namespace Nowo\UptimeMonitorBundle\Tests\Integration\Search;

use Doctrine\DBAL\Configuration as DbalConfiguration;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use Nowo\UptimeMonitorBundle\Entity\Monitor;
use Nowo\UptimeMonitorBundle\Repository\MonitorRepository;
use Nowo\UptimeMonitorBundle\Search\MonitorSearchHit;
use Nowo\UptimeMonitorBundle\Search\MonitorSearchMapper;
use Nowo\UptimeMonitorBundle\Search\MonitorSearchQuery;
use Nowo\UptimeMonitorBundle\Tests\Support\MonitorSearchFixtures;
use Nowo\UptimeMonitorBundle\Tests\Support\SqliteEntityManagerFactory;
use Nowo\UptimeMonitorBundle\Tests\Support\StatementLog;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

use function array_map;
use function count;
use function sort;

/**
 * The monitor-search candidate query on a real database (SQLite by default; MySQL / MariaDB / PostgreSQL through
 * UPTIME_TEST_DATABASE_URL): tenant scope, LIKE escaping, credentials never matched, query budget.
 */
#[Group('db')]
#[CoversClass(MonitorSearchQuery::class)]
#[CoversClass(MonitorSearchMapper::class)]
#[CoversClass(MonitorSearchHit::class)]
final class MonitorSearchQueryDbTest extends TestCase
{
    private EntityManagerInterface $em;
    private StatementLog $statements;
    private MonitorSearchQuery $query;

    protected function setUp(): void
    {
        $this->statements = new StatementLog();
        $this->em         = SqliteEntityManagerFactory::create((new DbalConfiguration())->setMiddlewares([$this->statements]));
        $registry         = $this->createStub(ManagerRegistry::class);
        $registry->method('getManagerForClass')->willReturn($this->em);
        $registry->method('getManager')->willReturn($this->em);
        MonitorSearchFixtures::load($this->em);
        $this->em->clear();
        $this->query = new MonitorSearchQuery(new MonitorRepository($registry));
    }

    protected function tearDown(): void
    {
        $this->em->getConnection()->close();
    }

    public function testMatchesNameAcrossTenants(): void
    {
        self::assertSame(['Billing API', 'Billing cron', 'Billing group', 'Billing hidden', 'Shared billing'], $this->names($this->query->find('billing', 50)));
    }

    public function testTenantScope(): void
    {
        self::assertSame(['Billing API', 'Billing cron', 'Billing group'], $this->names($this->query->find('billing', 50, 'acme')));
        self::assertSame([], $this->query->find('billing', 50, 'nobody'));
    }

    public function testMatchesTheDisplayedOrigin(): void
    {
        self::assertSame(['Website'], $this->names($this->query->find('www.example', 50)));
        self::assertSame(['Billing API'], $this->names($this->query->find('billing.example.com', 50)));
    }

    public function testCredentialsAndPushTargetsNeverMatch(): void
    {
        foreach (['hunter2', 'SECRETTOKEN', 'token=', 'user:', '/health', 'SECRETPUSH', 'pushtarget'] as $needle) {
            self::assertSame([], $this->query->find($needle, 50), $needle);
        }
    }

    public function testWildcardsAreEscaped(): void
    {
        self::assertSame(['100% uptime_check'], $this->names($this->query->find('100%', 50)));
        self::assertSame(['100% uptime_check'], $this->names($this->query->find('uptime_check', 50)));
        self::assertSame([], $this->query->find('!', 50));
        self::assertSame([], $this->query->find('%', 50, 'user-secret'));
    }

    public function testBlankNeedleAndNonPositiveLimit(): void
    {
        $this->statements->statements = [];
        self::assertSame([], $this->query->find('   ', 50));
        self::assertSame([], $this->query->find('billing', 0));
        self::assertSame(0, $this->statementCount());
    }

    public function testScanLimit(): void
    {
        self::assertCount(2, $this->query->find('billing', 2));
    }

    public function testMapperHidesWhatSearchMustNotShow(): void
    {
        $hits = [];
        foreach ($this->query->find('billing', 50) as $monitor) {
            $hits[$monitor->getName()] = (new MonitorSearchMapper())->map($monitor, true);
        }

        self::assertSame('https://billing.example.com', $hits['Billing API']->url);
        self::assertSame('up', $hits['Billing API']->status);
        self::assertSame('', $hits['Billing cron']->url);
        self::assertSame('', $hits['Billing group']->url);
        self::assertNull($hits['Billing group']->status);
        self::assertNull((new MonitorSearchMapper())->map($this->query->find('Billing API', 5)[0], false)->status);
        self::assertStringNotContainsString('hunter2', (string) json_encode($hits));
        self::assertStringNotContainsString('SECRET', (string) json_encode($hits));
    }

    public function testQueryBudget(): void
    {
        $this->statements->statements = [];
        $monitors                     = $this->query->find('billing', 50);
        $mapper                       = new MonitorSearchMapper();
        foreach ($monitors as $monitor) {
            $mapper->map($monitor, true);
        }

        self::assertCount(5, $monitors);
        self::assertSame(1, $this->statementCount(), 'one SELECT for the candidates, tenant joined: no N+1');
    }

    private function statementCount(): int
    {
        return count($this->statements->statements);
    }

    /**
     * @param list<Monitor> $monitors
     *
     * @return list<string>
     */
    private function names(array $monitors): array
    {
        $names = array_map(static fn (Monitor $m): string => $m->getName(), $monitors);
        sort($names);

        return $names;
    }
}
