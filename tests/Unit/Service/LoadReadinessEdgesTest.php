<?php

declare(strict_types=1);

namespace Nowo\UptimeMonitorBundle\Tests\Unit\Service;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use Nowo\UptimeMonitorBundle\Check\CheckRunnerInterface;
use Nowo\UptimeMonitorBundle\Entity\Monitor;
use Nowo\UptimeMonitorBundle\Entity\Tenant;
use Nowo\UptimeMonitorBundle\Enum\CheckStatus;
use Nowo\UptimeMonitorBundle\Enum\MonitorType;
use Nowo\UptimeMonitorBundle\Model\CheckResultDto;
use Nowo\UptimeMonitorBundle\Notification\NotificationChannelInterface;
use Nowo\UptimeMonitorBundle\Notification\UptimeAlert;
use Nowo\UptimeMonitorBundle\Repository\IncidentRepository;
use Nowo\UptimeMonitorBundle\Repository\MonitorRepository;
use Nowo\UptimeMonitorBundle\Service\CheckExecutorService;
use Nowo\UptimeMonitorBundle\Service\DueChecksRunner;
use Nowo\UptimeMonitorBundle\Service\MonitorGoneException;
use Nowo\UptimeMonitorBundle\Service\NotificationService;
use Nowo\UptimeMonitorBundle\Service\StatusTransitionService;
use Nowo\UptimeMonitorBundle\Tests\Support\SqliteEntityManagerFactory;
use Nowo\UptimeMonitorBundle\Tests\Unit\Support\SyncDispatcherTestTrait;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Symfony\Component\Clock\MockClock;

/**
 * 1.29 (Beacon spec 239): the edges of the claim, the locked check unit and the alerts sent after the commit.
 */
#[CoversClass(DueChecksRunner::class)]
#[CoversClass(CheckExecutorService::class)]
#[CoversClass(StatusTransitionService::class)]
#[CoversClass(MonitorGoneException::class)]
final class LoadReadinessEdgesTest extends TestCase
{
    use SyncDispatcherTestTrait;

    private EntityManagerInterface $em;
    private Tenant $tenant;

    protected function setUp(): void
    {
        $this->em     = SqliteEntityManagerFactory::create();
        $this->tenant = new Tenant('main', 'Main');
        $this->em->persist($this->tenant);
        $this->em->flush();
    }

    public function testAMonitorAnotherSchedulerClaimedIsSkipped(): void
    {
        $monitor = $this->monitor('API');
        $repo    = $this->createMock(MonitorRepository::class);
        $repo->method('findDue')->willReturn([$monitor]);
        $repo->expects(self::once())->method('claimDue')->with($monitor->getId(), $monitor->getNextCheckAt(), self::isInstanceOf(DateTimeImmutable::class))->willReturn(false);

        self::assertSame(0, (new DueChecksRunner($repo, $this->executor(), true, new MockClock(), 0))->runDueChecks());
        self::assertSame(0, SqliteEntityManagerFactory::count($this->em, 'uptime_check_result'));
    }

    /**
     * Audit QK-L3: a claim lasts the interval, but at least twice the request timeout plus a margin.
     */
    public function testTheClaimOutlastsASlowCheck(): void
    {
        $monitor = new Monitor($this->tenant, 'Slow', MonitorType::Https, 'https://slow.test');
        $monitor->setIntervalSeconds(30)->setConfig(['timeout' => 60]);
        self::assertSame(150, DueChecksRunner::leaseSeconds($monitor));
        $monitor->setIntervalSeconds(600);
        self::assertSame(600, DueChecksRunner::leaseSeconds($monitor));
    }

    public function testAClaimThatFailsSkipsTheMonitorWithAWarning(): void
    {
        $monitor = $this->monitor('API');
        $repo    = $this->createMock(MonitorRepository::class);
        $repo->method('findDue')->willReturn([$monitor]);
        $repo->method('claimDue')->willThrowException(new RuntimeException('lock wait'));
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())->method('warning')->with(self::anything(), self::callback(static fn (array $context): bool => $context['error'] === RuntimeException::class));

        self::assertSame(0, (new DueChecksRunner($repo, $this->executor(), true, new MockClock(), 0, $logger))->runDueChecks());
    }

    public function testAMonitorDeletedWhileItsCheckRanStoresNothing(): void
    {
        $monitor  = $this->monitor('API');
        $registry = $this->createStub(ManagerRegistry::class);
        $registry->method('getManagerForClass')->willReturn($this->em);
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::never())->method('error');
        $executor = $this->executor(function (Monitor $checked): void {
            $this->em->getConnection()->delete('uptime_monitor', ['id' => $checked->getId()]);
        });

        self::assertSame(0, (new DueChecksRunner(new MonitorRepository($registry), $executor, true, new MockClock(), 0, $logger))->runDueChecks(new DateTimeImmutable('+1 hour')));
        self::assertSame(0, SqliteEntityManagerFactory::count($this->em, 'uptime_check_result'));
        $gone = new MonitorGoneException((int) $monitor->getId());
        self::assertSame($monitor->getId(), $gone->monitorId);
        self::assertStringContainsString('deleted', $gone->getMessage());
    }

    public function testAGroupDeletedWhileItsChildWasCheckedEndsTheWalk(): void
    {
        $group = $this->monitor('Group', MonitorType::Group);
        $child = $this->monitor('API');
        $child->setParent($group);
        $this->em->flush();
        $executor = $this->executor(function (Monitor $checked) use ($group): void {
            if ($checked->getName() === 'API') {
                $this->em->getConnection()->delete('uptime_monitor', ['id' => $group->getId()]);
            }
        });

        $result = $executor->execute($child);

        self::assertSame('API', $result->getMonitor()->getName());
        self::assertSame(1, SqliteEntityManagerFactory::count($this->em, 'uptime_check_result'));
    }

    public function testAnAlertNoChannelDeliveredLeavesTheCooldownAlone(): void
    {
        $monitor = $this->monitor('API');
        $this->transitions($this->em, false)->sendCommitted([new UptimeAlert($monitor, UptimeAlert::TRANSITION_DOWN, CheckStatus::Down, CheckStatus::Up)]);

        self::assertNull($monitor->getLastAlertAt());
    }

    public function testADeliveredAlertWhoseTimeCannotBeStoredIsLogged(): void
    {
        $monitor    = $this->monitor('API');
        $connection = $this->createMock(Connection::class);
        $connection->method('beginTransaction')->willThrowException(new RuntimeException('connection lost'));
        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('getConnection')->willReturn($connection);
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())->method('warning')->with(self::stringContains('was sent'), self::callback(static fn (array $context): bool => $context['error'] === RuntimeException::class));

        $this->transitions($em, true, $logger)->sendCommitted([new UptimeAlert($monitor, UptimeAlert::TRANSITION_DOWN, CheckStatus::Down, CheckStatus::Up)]);
    }

    private function monitor(string $name, MonitorType $type = MonitorType::Https): Monitor
    {
        $monitor = new Monitor($this->tenant, $name, $type, 'https://api.test');
        $monitor->setNextCheckAt(new DateTimeImmutable('-1 minute'));
        $this->em->persist($monitor);
        $this->em->flush();

        return $monitor;
    }

    private function transitions(EntityManagerInterface $em, bool $delivers, ?LoggerInterface $logger = null): StatusTransitionService
    {
        $channel = $this->createStub(NotificationChannelInterface::class);
        $channel->method('isEnabled')->willReturn(true);
        $channel->method('getName')->willReturn('stub');
        $channel->method('send')->willReturn($delivers);
        $registry = $this->createStub(ManagerRegistry::class);
        $registry->method('getManagerForClass')->willReturn($this->em);

        return new StatusTransitionService($em, new IncidentRepository($registry), new NotificationService([$channel], ['enabled' => true]), ['enabled' => true], logger: $logger ?? $this->createStub(LoggerInterface::class));
    }

    /**
     * @param (callable(Monitor): void)|null $meanwhile What happens during the network step
     */
    private function executor(?callable $meanwhile = null): CheckExecutorService
    {
        $runner = $this->createStub(CheckRunnerInterface::class);
        $runner->method('supports')->willReturn(true);
        $runner->method('run')->willReturnCallback(static function (Monitor $monitor) use ($meanwhile): CheckResultDto {
            if ($meanwhile !== null) {
                $meanwhile($monitor);
            }

            return new CheckResultDto(CheckStatus::Up, 5);
        });

        return $this->checkExecutorService([$runner], $this->em);
    }
}
