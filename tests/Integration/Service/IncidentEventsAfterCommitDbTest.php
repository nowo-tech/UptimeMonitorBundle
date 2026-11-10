<?php

declare(strict_types=1);

namespace Nowo\UptimeMonitorBundle\Tests\Integration\Service;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use Nowo\UptimeMonitorBundle\Check\CheckRunnerInterface;
use Nowo\UptimeMonitorBundle\Entity\Incident;
use Nowo\UptimeMonitorBundle\Entity\Monitor;
use Nowo\UptimeMonitorBundle\Entity\Tag;
use Nowo\UptimeMonitorBundle\Entity\Tenant;
use Nowo\UptimeMonitorBundle\Enum\CheckStatus;
use Nowo\UptimeMonitorBundle\Enum\MonitorType;
use Nowo\UptimeMonitorBundle\Event\MonitorRecoveredEvent;
use Nowo\UptimeMonitorBundle\Event\MonitorWentDownEvent;
use Nowo\UptimeMonitorBundle\Model\CheckResultDto;
use Nowo\UptimeMonitorBundle\Model\IncidentSnapshot;
use Nowo\UptimeMonitorBundle\Repository\CheckAggregateRepository;
use Nowo\UptimeMonitorBundle\Repository\IncidentRepository;
use Nowo\UptimeMonitorBundle\Service\AggregateService;
use Nowo\UptimeMonitorBundle\Service\CheckExecutorService;
use Nowo\UptimeMonitorBundle\Service\CheckLatencyNormalizer;
use Nowo\UptimeMonitorBundle\Service\MonitorRetryService;
use Nowo\UptimeMonitorBundle\Service\NotificationService;
use Nowo\UptimeMonitorBundle\Service\StatusTransitionService;
use Nowo\UptimeMonitorBundle\Tests\Support\SqliteEntityManagerFactory;
use Nowo\UptimeMonitorBundle\Tests\Unit\Support\SyncDispatcherTestTrait;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Component\EventDispatcher\EventDispatcher;

/**
 * Since 1.25: the incident events of a check follow its flush (pattern 4 of the DDD audit).
 */
#[Group('db')]
#[CoversClass(CheckExecutorService::class)]
#[CoversClass(StatusTransitionService::class)]
#[CoversClass(MonitorWentDownEvent::class)]
#[CoversClass(MonitorRecoveredEvent::class)]
#[CoversClass(IncidentSnapshot::class)]
final class IncidentEventsAfterCommitDbTest extends TestCase
{
    use SyncDispatcherTestTrait;

    private EntityManagerInterface $em;
    private Tenant $tenant;
    private EventDispatcher $dispatcher;
    private CheckStatus $next = CheckStatus::Down;

    protected function setUp(): void
    {
        $this->em     = SqliteEntityManagerFactory::create();
        $this->tenant = new Tenant('main', 'Main');
        $this->em->persist($this->tenant);
        $this->em->flush();
        $this->dispatcher = new EventDispatcher();
    }

    public function testWentDownCarriesThePersistedIncidentIdAndRecoveredFollowsTheCommit(): void
    {
        $monitor = $this->monitor();
        $seen    = [];
        $this->dispatcher->addListener(MonitorWentDownEvent::class, function (MonitorWentDownEvent $event) use (&$seen): void {
            // Committed: a second connection-level query sees the incident row.
            $seen[] = ['down', $event->getIncidentId(), $this->countIncidents(), $event->snapshot()];
        });
        $this->dispatcher->addListener(MonitorRecoveredEvent::class, function (MonitorRecoveredEvent $event) use (&$seen): void {
            $seen[] = ['up', $event->getIncidentId(), $this->openIncidents(), $event->snapshot()];
        });
        $executor = $this->executor($this->em);

        $executor->execute($monitor);
        $this->next = CheckStatus::Up;
        $executor->execute($monitor);

        self::assertCount(2, $seen);
        [$kind, $id, $rows, $snapshot] = $seen[0];
        self::assertSame('down', $kind);
        self::assertIsInt($id);
        self::assertSame(1, $rows);
        self::assertSame('main', $snapshot->tenantSlug);
        self::assertSame($monitor->getId(), $snapshot->monitorId);
        self::assertSame('API', $snapshot->monitorName);
        self::assertSame(MonitorType::Https, $snapshot->monitorType);
        self::assertSame($id, $snapshot->incidentId);
        self::assertSame(CheckStatus::Down, $snapshot->triggerStatus);
        self::assertSame('timeout', $snapshot->message);
        self::assertNull($snapshot->endedAt);

        [$kind, $recoveredId, $open, $resolved] = $seen[1];
        self::assertSame('up', $kind);
        self::assertSame($id, $recoveredId);
        self::assertSame(0, $open, 'the resolution is committed');
        self::assertNotNull($resolved->endedAt);
    }

    public function testWhatAListenerPersistsIsStillFlushed(): void
    {
        $this->dispatcher->addListener(MonitorWentDownEvent::class, function (MonitorWentDownEvent $event): void {
            $this->em->persist(new Tag($this->tenant, 'incident-' . $event->getIncidentId()));
        });

        $this->executor($this->em)->execute($this->monitor());
        $this->em->clear();

        self::assertCount(1, $this->em->getRepository(Tag::class)->findAll());
    }

    public function testAFailedFlushDispatchesNothing(): void
    {
        $dispatched = false;
        $this->dispatcher->addListener(MonitorWentDownEvent::class, static function () use (&$dispatched): void {
            $dispatched = true;
        });
        $monitor = $this->monitor();
        $failing = $this->createMock(EntityManagerInterface::class);
        $failing->method('flush')->willThrowException(new RuntimeException('database down'));

        try {
            $this->executor($failing)->execute($monitor);
            self::fail('the flush failure propagates');
        } catch (RuntimeException) {
        }

        self::assertFalse($dispatched);
    }

    private function monitor(): Monitor
    {
        $monitor = new Monitor($this->tenant, 'API', MonitorType::Https, 'https://api.test');
        $monitor->setRetries(0);
        $monitor->setLastKnownStatus(CheckStatus::Up);
        $this->em->persist($monitor);
        $this->em->flush();

        return $monitor;
    }

    private function countIncidents(): int
    {
        return (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM ' . $this->em->getClassMetadata(Incident::class)->getTableName());
    }

    private function openIncidents(): int
    {
        return (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM ' . $this->em->getClassMetadata(Incident::class)->getTableName() . ' WHERE ended_at IS NULL');
    }

    private function executor(EntityManagerInterface $entityManager): CheckExecutorService
    {
        $registry = $this->createStub(ManagerRegistry::class);
        $registry->method('getManagerForClass')->willReturn($this->em);
        $runner = $this->createStub(CheckRunnerInterface::class);
        $runner->method('supports')->willReturn(true);
        $runner->method('run')->willReturnCallback(fn (): CheckResultDto => new CheckResultDto($this->next, 5, null, $this->next === CheckStatus::Up ? null : 'timeout'));

        return new CheckExecutorService(
            [$runner],
            $entityManager,
            new AggregateService($entityManager, new CheckAggregateRepository($registry), ['periods' => []]),
            new StatusTransitionService($entityManager, new IncidentRepository($registry), new NotificationService([], ['enabled' => false]), [], $this->dispatcher),
            $this->pollingSyncDispatcher(),
            new CheckLatencyNormalizer(['min_latency_ms' => 0]),
            new MonitorRetryService(),
        );
    }
}
