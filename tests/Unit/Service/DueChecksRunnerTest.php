<?php

declare(strict_types=1);

namespace Nowo\UptimeMonitorBundle\Tests\Unit\Service;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Query;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;
use Nowo\UptimeMonitorBundle\Check\CheckRunnerInterface;
use Nowo\UptimeMonitorBundle\Entity\CheckResult;
use Nowo\UptimeMonitorBundle\Entity\Monitor;
use Nowo\UptimeMonitorBundle\Entity\Tenant;
use Nowo\UptimeMonitorBundle\Enum\CheckStatus;
use Nowo\UptimeMonitorBundle\Enum\MonitorType;
use Nowo\UptimeMonitorBundle\Model\CheckResultDto;
use Nowo\UptimeMonitorBundle\Repository\MonitorRepository;
use Nowo\UptimeMonitorBundle\Service\DueChecksRunner;
use Nowo\UptimeMonitorBundle\Tests\Unit\Support\EntityIdTrait;
use Nowo\UptimeMonitorBundle\Tests\Unit\Support\SyncDispatcherTestTrait;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Symfony\Component\Clock\MockClock;

#[CoversClass(DueChecksRunner::class)]
final class DueChecksRunnerTest extends TestCase
{
    use EntityIdTrait;
    use SyncDispatcherTestTrait;

    public function testRunDueChecksExecutesEachMonitor(): void
    {
        $monitor = new Monitor(new Tenant('main', 'Main'), 'API', MonitorType::Https, 'https://example.test');

        $monitorRepository = $this->createMock(MonitorRepository::class);
        $monitorRepository->method('findDue')->willReturn([$monitor]);

        $runner = $this->createMock(CheckRunnerInterface::class);
        $runner->method('supports')->willReturn(true);
        $runner->method('run')->willReturn(new CheckResultDto(CheckStatus::Up, 1, 200));

        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('persist');
        $em->method('flush');

        $dueRunner = new DueChecksRunner(
            $monitorRepository,
            $this->checkExecutorService([$runner], $em),
        );

        self::assertSame(1, $dueRunner->runDueChecks());
    }

    public function testRunDueChecksReturnsZeroWhenNoneDue(): void
    {
        $monitorRepository = $this->createMock(MonitorRepository::class);
        $monitorRepository->method('findDue')->willReturn([]);

        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects(self::never())->method('flush');

        $dueRunner = new DueChecksRunner(
            $monitorRepository,
            $this->checkExecutorService([], $em),
        );

        self::assertSame(0, $dueRunner->runDueChecks());
    }

    public function testRunDueChecksIsNoOpWhenMonitoringDisabled(): void
    {
        $monitorRepository = $this->createMock(MonitorRepository::class);
        $monitorRepository->expects(self::never())->method('findDue');

        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects(self::never())->method('flush');

        $dueRunner = new DueChecksRunner(
            $monitorRepository,
            $this->checkExecutorService([], $em),
            enabled: false,
        );

        self::assertSame(0, $dueRunner->runDueChecks());
    }

    public function testAMonitorWhoseCheckThrowsIsRecordedAsUnknownAndRescheduled(): void
    {
        $broken = new Monitor(new Tenant('main', 'Main'), 'Broken', MonitorType::Https, 'https://broken.test');
        $ok     = new Monitor(new Tenant('main', 'Main'), 'OK', MonitorType::Tcp, 'ok.test');

        $monitorRepository = $this->createMock(MonitorRepository::class);
        $monitorRepository->method('findDue')->willReturn([$broken, $ok]);

        $runner = $this->createMock(CheckRunnerInterface::class);
        $runner->method('supports')->willReturn(true);
        $runner->method('run')->willReturnCallback(static function (Monitor $monitor): CheckResultDto {
            if ($monitor->getName() === 'Broken') {
                throw new RuntimeException('runaway check https://user:secret@broken.test');
            }

            return new CheckResultDto(CheckStatus::Up, 1);
        });

        $persisted = [];
        $em        = $this->createMock(EntityManagerInterface::class);
        $em->method('persist')->willReturnCallback(static function (object $entity) use (&$persisted): void {
            $persisted[] = $entity;
        });

        $dueRunner = new DueChecksRunner($monitorRepository, $this->checkExecutorService([$runner], $em));

        self::assertSame(2, $dueRunner->runDueChecks());
        $results = array_values(array_filter($persisted, static fn (object $e): bool => $e instanceof CheckResult));
        self::assertStringContainsString('Check failed (RuntimeException)', (string) $results[0]->getMessage());
        self::assertStringNotContainsString('secret', (string) $results[0]->getMessage());
        self::assertNotNull($broken->getNextCheckAt());
    }

    public function testAFailingExecutionIsLoggedAndTheScanContinues(): void
    {
        $first  = new Monitor(new Tenant('main', 'Main'), 'First', MonitorType::Https, 'https://a.test');
        $second = new Monitor(new Tenant('main', 'Main'), 'Second', MonitorType::Https, 'https://b.test');

        $monitorRepository = $this->createMock(MonitorRepository::class);
        $monitorRepository->method('findDue')->willReturn([$first, $second]);

        $runner = $this->createMock(CheckRunnerInterface::class);
        $runner->method('supports')->willReturn(true);
        $runner->method('run')->willReturn(new CheckResultDto(CheckStatus::Up, 1));

        $flushes = 0;
        $em      = $this->createMock(EntityManagerInterface::class);
        $em->method('flush')->willReturnCallback(static function () use (&$flushes): void {
            if (++$flushes === 1) {
                throw new RuntimeException('SQLSTATE secret');
            }
        });

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())->method('error')->with(
            self::anything(),
            self::callback(static fn (array $context): bool => $context['error'] === RuntimeException::class),
        );

        $dueRunner = new DueChecksRunner($monitorRepository, $this->checkExecutorService([$runner], $em), logger: $logger);

        self::assertSame(1, $dueRunner->runDueChecks());
    }

    public function testScanStopsWhenTheBudgetIsSpent(): void
    {
        $clock    = new MockClock('2026-01-01 00:00:00 UTC');
        $monitors = [
            new Monitor(new Tenant('main', 'Main'), 'A', MonitorType::Https, 'https://a.test'),
            new Monitor(new Tenant('main', 'Main'), 'B', MonitorType::Https, 'https://b.test'),
            new Monitor(new Tenant('main', 'Main'), 'C', MonitorType::Https, 'https://c.test'),
        ];

        $monitorRepository = $this->createMock(MonitorRepository::class);
        $monitorRepository->method('findDue')->willReturn($monitors);

        $runner = $this->createMock(CheckRunnerInterface::class);
        $runner->method('supports')->willReturn(true);
        $runner->method('run')->willReturnCallback(static function () use ($clock): CheckResultDto {
            $clock->sleep(40);

            return new CheckResultDto(CheckStatus::Up, 1);
        });

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())->method('warning');

        $dueRunner = new DueChecksRunner(
            $monitorRepository,
            $this->checkExecutorService([$runner], $this->createMock(EntityManagerInterface::class)),
            true,
            $clock,
            60,
            $logger,
        );

        self::assertSame(2, $dueRunner->runDueChecks());
    }

    public function testAClosedManagerIsResetTheMonitorRescheduledAndTheRestReloaded(): void
    {
        $tenant  = new Tenant('main', 'Main');
        $broken  = new Monitor($tenant, 'Broken', MonitorType::Https, 'https://a.test');
        $deleted = new Monitor($tenant, 'Deleted', MonitorType::Https, 'https://b.test');
        $this->setEntityId($broken, 1);
        $this->setEntityId($deleted, 2);

        $monitorRepository = $this->createMock(MonitorRepository::class);
        $monitorRepository->method('findDue')->willReturn([$broken, $deleted]);

        $runner = $this->createMock(CheckRunnerInterface::class);
        $runner->method('supports')->willReturn(true);
        $runner->method('run')->willReturn(new CheckResultDto(CheckStatus::Up, 1));

        $closed = $this->createMock(EntityManagerInterface::class);
        $closed->method('flush')->willThrowException(new RuntimeException('closed'));
        $closed->method('isOpen')->willReturn(false);

        $query = $this->createMock(Query::class);
        $query->expects(self::once())->method('execute');
        $qb = $this->createMock(QueryBuilder::class);
        $qb->method('update')->willReturnSelf();
        $qb->method('set')->willReturnSelf();
        $qb->method('where')->willReturnSelf();
        $qb->method('setParameter')->willReturnSelf();
        $qb->method('getQuery')->willReturn($query);
        $fresh = $this->createMock(EntityManagerInterface::class);
        $fresh->method('createQueryBuilder')->willReturn($qb);
        $fresh->expects(self::once())->method('find')->with(Monitor::class, 2)->willReturn(null);

        $current  = $closed;
        $registry = $this->createMock(ManagerRegistry::class);
        $registry->method('getManagerForClass')->willReturnCallback(static function () use (&$current): EntityManagerInterface {
            return $current;
        });
        $registry->method('getManagers')->willReturn(['other' => $this->createStub(EntityManagerInterface::class), 'default' => $closed]);
        $registry->expects(self::once())->method('resetManager')->with('default')->willReturnCallback(static function () use (&$current, $fresh): EntityManagerInterface {
            $current = $fresh;

            return $fresh;
        });

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())->method('warning');

        $dueRunner = new DueChecksRunner(
            $monitorRepository,
            $this->checkExecutorService([$runner], $closed),
            true,
            new MockClock('2026-01-01 00:00:00 UTC'),
            0,
            $logger,
            $registry,
        );

        // The broken monitor fails and is rescheduled; the second one no longer exists in the fresh manager.
        self::assertSame(0, $dueRunner->runDueChecks());
    }

    public function testAnOpenManagerIsNotReset(): void
    {
        $monitor = new Monitor(new Tenant('main', 'Main'), 'A', MonitorType::Https, 'https://a.test');

        $monitorRepository = $this->createMock(MonitorRepository::class);
        $monitorRepository->method('findDue')->willReturn([$monitor]);

        $runner = $this->createMock(CheckRunnerInterface::class);
        $runner->method('supports')->willReturn(true);
        $runner->method('run')->willReturn(new CheckResultDto(CheckStatus::Up, 1));

        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('flush')->willThrowException(new RuntimeException('deadlock'));
        $em->method('isOpen')->willReturn(true);

        $registry = $this->createMock(ManagerRegistry::class);
        $registry->method('getManagerForClass')->willReturn($em);
        $registry->expects(self::never())->method('resetManager');

        $dueRunner = new DueChecksRunner($monitorRepository, $this->checkExecutorService([$runner], $em), registry: $registry);

        self::assertSame(0, $dueRunner->runDueChecks());
    }
}
