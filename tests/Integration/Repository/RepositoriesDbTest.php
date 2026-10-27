<?php

declare(strict_types=1);

namespace Nowo\UptimeMonitorBundle\Tests\Integration\Repository;

use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use Nowo\UptimeMonitorBundle\Entity\CheckAggregate;
use Nowo\UptimeMonitorBundle\Entity\CheckResult;
use Nowo\UptimeMonitorBundle\Entity\Incident;
use Nowo\UptimeMonitorBundle\Entity\Monitor;
use Nowo\UptimeMonitorBundle\Entity\Tag;
use Nowo\UptimeMonitorBundle\Entity\Tenant;
use Nowo\UptimeMonitorBundle\Enum\AggregatePeriod;
use Nowo\UptimeMonitorBundle\Enum\CheckStatus;
use Nowo\UptimeMonitorBundle\Enum\MonitorType;
use Nowo\UptimeMonitorBundle\Repository\CheckAggregateRepository;
use Nowo\UptimeMonitorBundle\Repository\CheckResultRepository;
use Nowo\UptimeMonitorBundle\Repository\IncidentRepository;
use Nowo\UptimeMonitorBundle\Repository\MonitorRepository;
use Nowo\UptimeMonitorBundle\Repository\TagRepository;
use Nowo\UptimeMonitorBundle\Repository\TenantRepository;
use Nowo\UptimeMonitorBundle\Tests\Support\SqliteEntityManagerFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

use function array_map;

/**
 * Repository queries against a real Doctrine ORM + SQLite (in-memory) schema built from the bundle mapping.
 */
#[Group('db')]
#[CoversClass(MonitorRepository::class)]
#[CoversClass(CheckResultRepository::class)]
#[CoversClass(CheckAggregateRepository::class)]
#[CoversClass(IncidentRepository::class)]
#[CoversClass(TagRepository::class)]
#[CoversClass(TenantRepository::class)]
final class RepositoriesDbTest extends TestCase
{
    private EntityManagerInterface $em;
    private ManagerRegistry $registry;
    private Tenant $tenant;
    private Tenant $other;

    protected function setUp(): void
    {
        $this->em = SqliteEntityManagerFactory::create();
        $registry = $this->createStub(ManagerRegistry::class);
        $registry->method('getManagerForClass')->willReturn($this->em);
        $registry->method('getManager')->willReturn($this->em);
        $this->registry = $registry;

        $this->tenant = new Tenant('main', 'Main');
        $this->other  = new Tenant('other', 'Other');
        $this->em->persist($this->tenant);
        $this->em->persist($this->other);
        $this->em->flush();
    }

    private function monitor(string $name, MonitorType $type = MonitorType::Https, ?Tenant $tenant = null): Monitor
    {
        $monitor = new Monitor($tenant ?? $this->tenant, $name, $type, 'https://' . $name . '.test');
        $this->em->persist($monitor);

        return $monitor;
    }

    private function check(Monitor $monitor, string $at, CheckStatus $status = CheckStatus::Up): CheckResult
    {
        $result = new CheckResult($monitor, $status, 10, 200, null, null, new DateTimeImmutable($at));
        $this->em->persist($result);

        return $result;
    }

    /**
     * @param list<Monitor|Tag> $entities
     *
     * @return list<string>
     */
    private function names(array $entities): array
    {
        return array_map(static fn (Monitor|Tag $e): string => $e->getName(), $entities);
    }

    public function testMonitorRepositoryQueries(): void
    {
        $group  = $this->monitor('group', MonitorType::Group);
        $b      = $this->monitor('b-api');
        $a      = $this->monitor('a-api');
        $paused = $this->monitor('paused');
        $future = $this->monitor('future');
        $this->monitor('foreign', MonitorType::Https, $this->other);

        $b->setParent($group)->setNextCheckAt(new DateTimeImmutable('2026-01-01 10:00:00'));
        $a->setParent($group)->setNextCheckAt(null);
        $group->setNextCheckAt(new DateTimeImmutable('2026-01-01 09:00:00'));
        $paused->setPaused(true)->setNextCheckAt(new DateTimeImmutable('2026-01-01 08:00:00'));
        $future->setNextCheckAt(new DateTimeImmutable('2026-01-02 00:00:00'));
        $this->em->flush();

        $repository = new MonitorRepository($this->registry);

        self::assertSame(['a-api', 'b-api', 'future', 'group', 'paused'], $this->names($repository->findByTenantSlug('main')));
        self::assertSame(['group'], $this->names($repository->findGroupsByTenantSlug('main')));
        // Groups are not counted against the tenant limit; other tenants' monitors neither.
        self::assertSame(4, $repository->countChecksByTenantSlug('main'));
        self::assertSame(['a-api', 'b-api'], $this->names($repository->findChildrenOf((int) $group->getId())));

        // Paused and not-yet-due monitors are skipped; where NULL next_check_at sorts is platform-specific.
        $due = $this->names($repository->findDue(new DateTimeImmutable('2026-01-01 12:00:00')));
        self::assertEqualsCanonicalizing(['a-api', 'group', 'b-api'], $due);
        self::assertLessThan(array_search('b-api', $due, true), array_search('group', $due, true));
    }

    public function testFindDueSkipsMonitorsOfDisabledTenants(): void
    {
        $mine    = $this->monitor('mine');
        $foreign = $this->monitor('foreign', MonitorType::Https, $this->other);
        $mine->setNextCheckAt(new DateTimeImmutable('2026-01-01 08:00:00'));
        $foreign->setNextCheckAt(new DateTimeImmutable('2026-01-01 08:00:00'));
        $this->other->setEnabled(false);
        $this->em->flush();

        $repository = new MonitorRepository($this->registry);
        $now        = new DateTimeImmutable('2026-01-01 12:00:00');

        self::assertSame(['mine'], $this->names($repository->findDue($now)));

        $this->other->setEnabled(true);
        $this->em->flush();
        self::assertEqualsCanonicalizing(['mine', 'foreign'], $this->names($repository->findDue($now)));
    }

    public function testCheckResultRepositoryQueries(): void
    {
        $one     = $this->monitor('one');
        $two     = $this->monitor('two');
        $foreign = $this->monitor('foreign', MonitorType::Https, $this->other);
        $this->em->flush();

        $old    = $this->check($one, '2026-01-01 00:00:00');
        $mid    = $this->check($one, '2026-01-02 00:00:00', CheckStatus::Down);
        $latest = $this->check($one, '2026-01-03 00:00:00');
        $twoOld = $this->check($two, '2026-01-01 12:00:00');
        $this->check($foreign, '2026-01-04 00:00:00');
        $this->em->flush();

        $repository = new CheckResultRepository($this->registry);
        $since      = new DateTimeImmutable('2026-01-01 06:00:00');
        $oneId      = (int) $one->getId();
        $twoId      = (int) $two->getId();

        self::assertSame($latest, $repository->findLatestForMonitor($one));
        $empty = $this->monitor('empty');
        $this->em->flush();
        self::assertNull($repository->findLatestForMonitor($empty));

        self::assertSame([], $repository->findLatestByMonitorIds([]));
        self::assertSame([$oneId => $latest, $twoId => $twoOld], $repository->findLatestByMonitorIds([$oneId, $twoId]));

        self::assertSame([], $repository->findRecentByMonitorIds([], $since, 5));
        self::assertSame([], $repository->findRecentByMonitorIds([$oneId], $since, 0));
        self::assertSame([$oneId => [$latest]], $repository->findRecentByMonitorIds([$oneId], $since, 1));
        self::assertSame(
            [$oneId => [$mid, $latest], $twoId => [$twoOld]],
            $repository->findRecentByMonitorIds([$oneId, $twoId], $since, 5),
        );

        self::assertSame([], $repository->findChecksSinceByMonitorIds([], $since));
        self::assertSame(
            [$twoId => [$twoOld], $oneId => [$mid, $latest]],
            $repository->findChecksSinceByMonitorIds([$oneId, $twoId], $since),
        );

        self::assertSame([$mid, $latest], $repository->findRecentForMonitor($one, $since, 10));
        self::assertSame([$latest], $repository->findRecentForMonitor($one, null, 0));
        self::assertSame([$old, $mid, $latest], $repository->findRecentForMonitor($one, null, 10));

        self::assertSame([$mid, $latest], $repository->findChecksForMonitorSince($one, $since));

        self::assertSame([$latest, $mid, $twoOld, $old], $repository->findRecentForTenant('main'));
        self::assertSame([$latest], $repository->findRecentForTenant('main', 0));
    }

    public function testCheckAggregateRepositoryQueries(): void
    {
        $one = $this->monitor('one');
        $two = $this->monitor('two');
        $this->em->flush();

        $day1  = new CheckAggregate($one, AggregatePeriod::Day, new DateTimeImmutable('2026-01-01 00:00:00'));
        $day2  = new CheckAggregate($one, AggregatePeriod::Day, new DateTimeImmutable('2026-01-02 00:00:00'));
        $hour  = new CheckAggregate($one, AggregatePeriod::Hour, new DateTimeImmutable('2026-01-01 05:00:00'));
        $twoD1 = new CheckAggregate($two, AggregatePeriod::Day, new DateTimeImmutable('2026-01-01 00:00:00'));
        $late  = new CheckAggregate($two, AggregatePeriod::Day, new DateTimeImmutable('2026-02-01 00:00:00'));
        foreach ([$day1, $day2, $hour, $twoD1, $late] as $aggregate) {
            $this->em->persist($aggregate);
        }
        $this->em->flush();

        $repository = new CheckAggregateRepository($this->registry);
        $from       = new DateTimeImmutable('2026-01-01 00:00:00');
        $to         = new DateTimeImmutable('2026-01-31 00:00:00');

        self::assertSame($day2, $repository->findOneForBucket($one, AggregatePeriod::Day, new DateTimeImmutable('2026-01-02 00:00:00')));
        self::assertNull($repository->findOneForBucket($one, AggregatePeriod::Month, $from));

        self::assertSame([$day1, $day2], $repository->findForMonitorInRange($one, AggregatePeriod::Day, $from, $to));
        self::assertSame([$hour], $repository->findForMonitorInRange($one, AggregatePeriod::Hour, $from, $to));

        $overview = $repository->findTenantOverview('main', AggregatePeriod::Day, $from, $to);
        self::assertCount(3, $overview);
        self::assertSame([], $repository->findTenantOverview('other', AggregatePeriod::Day, $from, $to));

        self::assertSame([], $repository->findDailyAggregatesByMonitorIds([], $from, $to));
        self::assertSame(
            [(int) $one->getId() => [$day1, $day2], (int) $two->getId() => [$twoD1]],
            $repository->findDailyAggregatesByMonitorIds([(int) $one->getId(), (int) $two->getId()], $from, $to),
        );
    }

    public function testIncidentTagAndTenantRepositories(): void
    {
        $monitor = $this->monitor('one');
        $this->em->flush();

        $closed = (new Incident($monitor, CheckStatus::Down))->resolve(new DateTimeImmutable('2026-01-01 00:00:00'));
        $this->em->persist($closed);
        $this->em->flush();

        $incidents = new IncidentRepository($this->registry);
        self::assertNull($incidents->findOpenForMonitor($monitor));

        $open = new Incident($monitor, CheckStatus::Degraded, 'slow');
        $this->em->persist($open);
        $this->em->flush();
        self::assertSame($open, $incidents->findOpenForMonitor($monitor));

        $this->em->persist(new Tag($this->tenant, 'zeta'));
        $this->em->persist(new Tag($this->tenant, 'alpha'));
        $this->em->persist(new Tag($this->other, 'beta'));
        $this->em->flush();

        self::assertSame(['alpha', 'zeta'], $this->names((new TagRepository($this->registry))->findByTenant($this->tenant)));

        $tenants = new TenantRepository($this->registry);
        self::assertSame($this->other, $tenants->findOneBySlug('other'));
        self::assertNull($tenants->findOneBySlug('missing'));
    }
}
