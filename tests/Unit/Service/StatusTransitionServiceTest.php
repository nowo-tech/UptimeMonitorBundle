<?php

declare(strict_types=1);

namespace Nowo\UptimeMonitorBundle\Tests\Unit\Service;

use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Nowo\UptimeMonitorBundle\Entity\CheckResult;
use Nowo\UptimeMonitorBundle\Entity\Incident;
use Nowo\UptimeMonitorBundle\Entity\Monitor;
use Nowo\UptimeMonitorBundle\Entity\Tenant;
use Nowo\UptimeMonitorBundle\Enum\CheckStatus;
use Nowo\UptimeMonitorBundle\Enum\MonitorType;
use Nowo\UptimeMonitorBundle\Notification\UptimeAlert;
use Nowo\UptimeMonitorBundle\Repository\IncidentRepository;
use Nowo\UptimeMonitorBundle\Service\NotificationService;
use Nowo\UptimeMonitorBundle\Service\StatusTransitionService;
use PHPUnit\Framework\TestCase;

/**
 * @covers \Nowo\UptimeMonitorBundle\Service\StatusTransitionService
 */
final class StatusTransitionServiceTest extends TestCase
{
    /**
     * @param array<string, mixed> $config
     */
    private function createService(
        IncidentRepository $incidentRepo,
        NotificationService $notificationService,
        array $config = ['enabled' => true, 'cooldown_seconds' => 300],
        ?EntityManagerInterface $entityManager = null,
    ): StatusTransitionService {
        return new StatusTransitionService(
            $entityManager ?? $this->createMock(EntityManagerInterface::class),
            $incidentRepo,
            $notificationService,
            $config,
        );
    }

    private function createMonitor(): Monitor
    {
        return new Monitor(new Tenant('main', 'Main'), 'Site', MonitorType::Https, 'https://example.test');
    }

    public function testSetsInitialStatusWithoutNotification(): void
    {
        $monitor = $this->createMonitor();
        $result  = new CheckResult($monitor, CheckStatus::Up, 10);

        $notificationService = $this->createMock(NotificationService::class);
        $notificationService->expects(self::never())->method('notify');

        $service = $this->createService(
            $this->createMock(IncidentRepository::class),
            $notificationService,
        );

        $service->handleAfterCheck($monitor, $result);

        self::assertSame(CheckStatus::Up, $monitor->getLastKnownStatus());
    }

    public function testNotifiesOnTransitionToDown(): void
    {
        $monitor = $this->createMonitor();
        $monitor->setLastKnownStatus(CheckStatus::Up);
        $result = new CheckResult($monitor, CheckStatus::Down, 100, 503, 'down');

        $incidentRepo = $this->createMock(IncidentRepository::class);
        $incidentRepo->method('findOpenForMonitor')->willReturn(null);

        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects(self::once())->method('persist');

        $notificationService = $this->createMock(NotificationService::class);
        $notificationService->expects(self::once())
            ->method('notify')
            ->with(self::callback(static fn (UptimeAlert $alert): bool => $alert->getTransition() === UptimeAlert::TRANSITION_DOWN))
            ->willReturn(1);

        $service = $this->createService($incidentRepo, $notificationService, entityManager: $entityManager);
        $service->handleAfterCheck($monitor, $result);

        self::assertSame(CheckStatus::Down, $monitor->getLastKnownStatus());
        self::assertNotNull($monitor->getLastAlertAt());
    }

    public function testNotifiesOnRecovery(): void
    {
        $monitor = $this->createMonitor();
        $monitor->setLastKnownStatus(CheckStatus::Down);
        $result = new CheckResult($monitor, CheckStatus::Up, 50, 200);

        $open         = new Incident($monitor, CheckStatus::Down);
        $incidentRepo = $this->createMock(IncidentRepository::class);
        $incidentRepo->method('findOpenForMonitor')->willReturn($open);

        $notificationService = $this->createMock(NotificationService::class);
        $notificationService->expects(self::once())
            ->method('notify')
            ->with(self::callback(static fn (UptimeAlert $alert): bool => $alert->getTransition() === UptimeAlert::TRANSITION_UP))
            ->willReturn(1);

        $service = $this->createService($incidentRepo, $notificationService);
        $service->handleAfterCheck($monitor, $result);

        self::assertFalse($open->isOpen());
    }

    public function testSkipsWhenStatusUnchanged(): void
    {
        $monitor = $this->createMonitor();
        $monitor->setLastKnownStatus(CheckStatus::Up);
        $result = new CheckResult($monitor, CheckStatus::Up, 10);

        $notificationService = $this->createMock(NotificationService::class);
        $notificationService->expects(self::never())->method('notify');

        $service = $this->createService($this->createMock(IncidentRepository::class), $notificationService);
        $service->handleAfterCheck($monitor, $result);
    }

    public function testRespectsNotificationCooldown(): void
    {
        $monitor = $this->createMonitor();
        $monitor->setLastKnownStatus(CheckStatus::Up);
        $monitor->setLastAlertAt(new DateTimeImmutable());
        $result = new CheckResult($monitor, CheckStatus::Down, 100);

        $incidentRepo = $this->createMock(IncidentRepository::class);
        $incidentRepo->method('findOpenForMonitor')->willReturn(null);

        $notificationService = $this->createMock(NotificationService::class);
        $notificationService->expects(self::never())->method('notify');

        $service         = $this->createService($incidentRepo, $notificationService);
        $previousAlertAt = $monitor->getLastAlertAt();
        $service->handleAfterCheck($monitor, $result);

        self::assertSame($previousAlertAt, $monitor->getLastAlertAt());
    }

    public function testCreatesIncidentOnDegraded(): void
    {
        $monitor = $this->createMonitor();
        $monitor->setLastKnownStatus(CheckStatus::Up);
        $result = new CheckResult($monitor, CheckStatus::Degraded, 80, null, 'slow');

        $incidentRepo = $this->createMock(IncidentRepository::class);
        $incidentRepo->method('findOpenForMonitor')->willReturn(null);

        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects(self::once())->method('persist')
            ->with(self::isInstanceOf(Incident::class));

        $notificationService = $this->createMock(NotificationService::class);
        $notificationService->method('notify')->willReturn(0);

        $service = $this->createService($incidentRepo, $notificationService, entityManager: $entityManager);
        $service->handleAfterCheck($monitor, $result);
    }

    public function testNotificationsDisabledInConfig(): void
    {
        $monitor = $this->createMonitor();
        $monitor->setLastKnownStatus(CheckStatus::Up);
        $result = new CheckResult($monitor, CheckStatus::Down, 100);

        $incidentRepo = $this->createMock(IncidentRepository::class);
        $incidentRepo->method('findOpenForMonitor')->willReturn(null);

        $notificationService = $this->createMock(NotificationService::class);
        $notificationService->expects(self::never())->method('notify');

        $service = $this->createService(
            $incidentRepo,
            $notificationService,
            ['enabled' => false, 'cooldown_seconds' => 0],
        );
        $service->handleAfterCheck($monitor, $result);
    }
}
