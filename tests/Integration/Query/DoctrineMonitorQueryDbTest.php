<?php

declare(strict_types=1);

namespace Nowo\UptimeMonitorBundle\Tests\Integration\Query;

use DateTimeImmutable;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\EntityManagerInterface;
use Nowo\UptimeMonitorBundle\Entity\CheckResult;
use Nowo\UptimeMonitorBundle\Entity\Monitor;
use Nowo\UptimeMonitorBundle\Entity\Tenant;
use Nowo\UptimeMonitorBundle\Enum\CheckStatus;
use Nowo\UptimeMonitorBundle\Enum\MonitorType;
use Nowo\UptimeMonitorBundle\Query\DoctrineMonitorQuery;
use Nowo\UptimeMonitorBundle\Query\LatestCheckView;
use Nowo\UptimeMonitorBundle\Query\MonitorCounts;
use Nowo\UptimeMonitorBundle\Query\MonitorListCriteria;
use Nowo\UptimeMonitorBundle\Query\MonitorListSort;
use Nowo\UptimeMonitorBundle\Query\MonitorListStatus;
use Nowo\UptimeMonitorBundle\Query\MonitorRef;
use Nowo\UptimeMonitorBundle\Query\MonitorView;
use Nowo\UptimeMonitorBundle\Tests\Support\SqliteEntityManagerFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

use function array_map;

/**
 * The cross-tenant monitor query (1.35, Beacon spec 225) over a real schema (SQLite by default, MySQL / MariaDB /
 * PostgreSQL with UPTIME_TEST_DATABASE_URL): the scope is the slug list only, filters narrow it, nothing is managed.
 */
#[Group('db')]
#[CoversClass(DoctrineMonitorQuery::class)]
#[CoversClass(MonitorListCriteria::class)]
#[CoversClass(MonitorView::class)]
#[CoversClass(MonitorRef::class)]
#[CoversClass(MonitorCounts::class)]
#[CoversClass(LatestCheckView::class)]
final class DoctrineMonitorQueryDbTest extends TestCase
{
    private EntityManagerInterface $em;
    private DoctrineMonitorQuery $query;
    private Tenant $alpha;
    private Tenant $beta;
    private Tenant $gamma;

    /** @var array<string, Monitor> */
    private array $m = [];

    protected function setUp(): void
    {
        $this->em    = SqliteEntityManagerFactory::create();
        $this->query = new DoctrineMonitorQuery($this->em);
        $this->alpha = new Tenant('alpha', 'Alpha');
        $this->beta  = new Tenant('beta', 'Beta');
        $this->gamma = new Tenant('gamma', 'Gamma');
        foreach ([$this->alpha, $this->beta, $this->gamma] as $tenant) {
            $this->em->persist($tenant);
        }

        $this->add('a-down', $this->alpha, MonitorType::Https, CheckStatus::Down);
        $this->add('a-up', $this->alpha, MonitorType::Https, CheckStatus::Up, interval: 300);
        $this->add('a-push', $this->alpha, MonitorType::Push, CheckStatus::Degraded, interval: 120);
        $this->add('a-new', $this->alpha, MonitorType::Tcp, null);
        $this->add('a-paused', $this->alpha, MonitorType::Https, CheckStatus::Down, paused: true);
        $this->add('a-group', $this->alpha, MonitorType::Group, CheckStatus::Up);
        $this->add('b-up', $this->beta, MonitorType::Https, CheckStatus::Up, target: 'https://user:s3cr3t@b.test/health?token=abc');
        $this->add('b-push', $this->beta, MonitorType::Push, CheckStatus::Down);
        $this->add('g-shared', $this->gamma, MonitorType::Https, CheckStatus::Unknown);
        $this->add('g-other', $this->gamma, MonitorType::Https, CheckStatus::Down);
        $this->em->flush();
        $this->em->clear();
    }

    public function testAnEmptyScopeReadsNothing(): void
    {
        $empty = new MonitorListCriteria();

        self::assertTrue($empty->isEmpty());
        self::assertSame([], $this->query->list($empty));
        self::assertSame(0, $this->query->count($empty));
        self::assertSame(0, $this->query->count(new MonitorListCriteria(['alpha'], monitorIds: [])), 'an empty id list matches nothing');
        self::assertEquals(new MonitorCounts(0, 0), $this->query->countActive([]));
        self::assertSame(0, $this->query->countDown([]));
    }

    public function testOnlyValidSlugsCompareAndTheyCompareExactly(): void
    {
        $criteria = new MonitorListCriteria(['ALPHA', 'alpha ', '', 'beta', 'beta'], [new MonitorRef('GAMMA', 1)]);

        self::assertSame(['beta'], $criteria->tenantSlugs);
        self::assertSame([], $criteria->grantedMonitors);
        self::assertSame(['b-push', 'b-up'], $this->names($this->query->list($criteria)));
        self::assertSame(0, $this->query->count(new MonitorListCriteria(['ALPHA'])));
        self::assertEquals(new MonitorCounts(0, 0), $this->query->countActive(['Alpha']));
    }

    public function testTheDashboardListIsProblemsFirstWithGroupsLeftOut(): void
    {
        $criteria = new MonitorListCriteria(['alpha']);

        self::assertSame(['a-down', 'a-push', 'a-new', 'a-up', 'a-paused'], $this->names($this->query->list($criteria)));
        self::assertSame(5, $this->query->count($criteria));
        self::assertSame(['a-paused', 'a-up', 'a-new', 'a-push', 'a-down'], $this->names($this->query->list($criteria, descending: true)));
        self::assertSame(6, $this->query->count(new MonitorListCriteria(['alpha'], includeGroups: true)));
    }

    public function testGrantedMonitorsAreBoundToTheirTenant(): void
    {
        $shared   = (int) $this->m['g-shared']->getId();
        $criteria = new MonitorListCriteria(['beta'], [new MonitorRef('gamma', $shared), new MonitorRef('beta', (int) $this->m['a-up']->getId())]);

        self::assertSame(['b-push', 'g-shared', 'b-up'], $this->names($this->query->list($criteria)));
        self::assertSame(3, $this->query->count($criteria), 'a-up granted under the wrong tenant is not read');
        self::assertTrue($criteria->covers('gamma', $shared));
        self::assertFalse($criteria->covers('gamma', (int) $this->m['g-other']->getId()));
        self::assertSame(['g-shared'], $this->names($this->query->list(new MonitorListCriteria([], [new MonitorRef('gamma', $shared)]))));
    }

    public function testFiltersNarrowTheScope(): void
    {
        $scope = ['alpha', 'beta'];

        self::assertSame(['b-push', 'a-push'], $this->names($this->query->list(new MonitorListCriteria($scope, type: MonitorType::Push))));
        self::assertSame(['a-down', 'b-push'], $this->names($this->query->list(new MonitorListCriteria($scope, status: MonitorListStatus::Down))));
        self::assertSame(['a-paused'], $this->names($this->query->list(new MonitorListCriteria($scope, status: MonitorListStatus::Paused))));
        self::assertSame(['a-new'], $this->names($this->query->list(new MonitorListCriteria($scope, status: MonitorListStatus::Unknown))));
        self::assertSame(['a-push'], $this->names($this->query->list(new MonitorListCriteria($scope, status: MonitorListStatus::Degraded))));
        self::assertSame(['a-up', 'b-up'], $this->names($this->query->list(new MonitorListCriteria($scope, status: MonitorListStatus::Up), MonitorListSort::Name)));
        self::assertSame(['a-paused'], $this->names($this->query->list(new MonitorListCriteria($scope, paused: true))));
        self::assertSame(6, $this->query->count(new MonitorListCriteria($scope, paused: false)));
        $ids = [(int) $this->m['a-up']->getId(), (int) $this->m['b-up']->getId(), (int) $this->m['g-other']->getId()];
        self::assertSame(['a-up', 'b-up'], $this->names($this->query->list(new MonitorListCriteria($scope, monitorIds: $ids), MonitorListSort::Name)));
        self::assertSame(['g-shared'], $this->names($this->query->list(new MonitorListCriteria(['gamma'], status: MonitorListStatus::Unknown))));
    }

    public function testSortKeysAndPaging(): void
    {
        $criteria = new MonitorListCriteria(['alpha']);

        self::assertSame(['a-down', 'a-new', 'a-paused', 'a-push', 'a-up'], $this->names($this->query->list($criteria, MonitorListSort::Name)));
        self::assertSame(['a-down', 'a-paused', 'a-up', 'a-push', 'a-new'], $this->names($this->query->list($criteria, MonitorListSort::Type)));
        self::assertSame(['a-down', 'a-new', 'a-paused', 'a-push', 'a-up'], $this->names($this->query->list($criteria, MonitorListSort::Interval)));
        self::assertSame(['a-down', 'a-up', 'a-push', 'a-new', 'a-paused'], $this->names($this->query->list($criteria, MonitorListSort::Id)));
        self::assertSame(['a-up', 'a-push'], $this->names($this->query->list($criteria, MonitorListSort::Id, limit: 2, offset: 1)));
        self::assertSame(['a-down'], $this->names($this->query->list($criteria, MonitorListSort::Id, limit: 0)), 'the limit is at least one');
        self::assertCount(5, $this->query->list($criteria, limit: 10_000, offset: -3));
    }

    public function testViewsArePlainValuesWithTheLatestCheck(): void
    {
        $monitor = $this->em->find(Monitor::class, (int) $this->m['b-up']->getId());
        self::assertInstanceOf(Monitor::class, $monitor);
        $this->addResult($monitor, CheckStatus::Down, 900, '2026-05-01 10:00:00');
        $this->addResult($monitor, CheckStatus::Up, 42, '2026-05-01 11:00:00', 200);
        $this->em->clear();

        [$view] = $this->query->list(new MonitorListCriteria(['beta'], type: MonitorType::Https));
        self::assertSame('b-up', $view->name);
        self::assertSame('beta', $view->tenantSlug);
        self::assertSame('Beta', $view->tenantName);
        self::assertSame(MonitorType::Https, $view->type);
        self::assertStringNotContainsString('s3cr3t', $view->target);
        self::assertSame(60, $view->intervalSeconds);
        self::assertFalse($view->paused);
        self::assertSame(CheckStatus::Up, $view->lastKnownStatus);
        self::assertSame('up', $view->displayStatus());
        self::assertNull($view->parentId);
        self::assertInstanceOf(LatestCheckView::class, $view->latest);
        self::assertSame([CheckStatus::Up, 42, 200, '2026-05-01 11:00'], [$view->latest->status, $view->latest->latencyMs, $view->latest->statusCode, $view->latest->checkedAt->format('Y-m-d H:i')]);
        self::assertNull($this->query->list(new MonitorListCriteria(['beta'], type: MonitorType::Https), withLatest: false)[0]->latest);
        self::assertSame(0, $this->em->getUnitOfWork()->size(), 'no entity is managed');

        $paused = $this->query->findInTenant('alpha', (int) $this->m['a-paused']->getId());
        self::assertSame('paused', $paused?->displayStatus());
        self::assertSame('unknown', $this->query->findInTenant('alpha', (int) $this->m['a-new']->getId())?->displayStatus());
    }

    public function testFindInTenantNeedsTheExactTenant(): void
    {
        $id = (int) $this->m['a-group']->getId();

        self::assertSame('a-group', $this->query->findInTenant('alpha', $id)?->name, 'groups are found by id');
        self::assertNull($this->query->findInTenant('beta', $id));
        self::assertNull($this->query->findInTenant('ALPHA', $id));
        self::assertNull($this->query->findInTenant('alpha', 0));
        self::assertNull($this->query->findInTenant('alpha', $id + 1000));
        self::assertNull($this->query->findInTenant('alpha', $id, true)?->latest);
    }

    public function testPlanUsageAndDownCount(): void
    {
        self::assertEquals(new MonitorCounts(4, 1), $this->query->countActive(['alpha']), 'paused and group left out');
        self::assertEquals(new MonitorCounts(6, 2), $this->query->countActive(['alpha', 'beta', 'nobody']));
        self::assertSame(2, $this->query->countDown(['alpha', 'beta']), 'the paused down monitor is not counted');
        self::assertSame(1, $this->query->countDown(['gamma']));
    }

    /**
     * @param list<MonitorView> $views
     *
     * @return list<string>
     */
    private function names(array $views): array
    {
        return array_map(static fn (MonitorView $view): string => $view->name, $views);
    }

    private function add(string $name, Tenant $tenant, MonitorType $type, ?CheckStatus $status, bool $paused = false, int $interval = 60, ?string $target = null): void
    {
        $monitor = (new Monitor($tenant, $name, $type, $target ?? 'https://' . $name . '.test'))
            ->setPaused($paused)
            ->setIntervalSeconds($interval)
            ->setLastKnownStatus($status);
        $this->em->persist($monitor);
        $this->m[$name] = $monitor;
    }

    private function addResult(Monitor $monitor, CheckStatus $status, int $latency, string $at, ?int $code = null): void
    {
        $result = new CheckResult($monitor, $status, $latency, $code);
        $this->em->persist($result);
        $this->em->flush();
        $this->em->getConnection()->executeStatement('UPDATE uptime_check_result SET checked_at = ? WHERE id = ?', [new DateTimeImmutable($at), $result->getId()], [Types::DATETIME_IMMUTABLE, Types::INTEGER]);
    }
}
