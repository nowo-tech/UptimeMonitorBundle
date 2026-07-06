<?php

declare(strict_types=1);

namespace Nowo\UptimeMonitorBundle\Tests\Unit\Service;

use Nowo\UptimeMonitorBundle\Check\CheckRunnerInterface;
use Nowo\UptimeMonitorBundle\Entity\Monitor;
use Nowo\UptimeMonitorBundle\Entity\Tenant;
use Nowo\UptimeMonitorBundle\Enum\CheckStatus;
use Nowo\UptimeMonitorBundle\Enum\MonitorType;
use Nowo\UptimeMonitorBundle\Model\CheckResultDto;
use Nowo\UptimeMonitorBundle\Tests\Unit\Support\SyncDispatcherTestTrait;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;

/**
 * @covers \Nowo\UptimeMonitorBundle\Service\CheckExecutorService
 */
final class CheckExecutorServiceTest extends TestCase
{
    use SyncDispatcherTestTrait;

    public function testExecuteRunsMatchingRunnerAndPersists(): void
    {
        $tenant  = new Tenant('main', 'Main');
        $monitor = new Monitor($tenant, 'API', MonitorType::Https, 'https://example.test');

        $runner = $this->createMock(CheckRunnerInterface::class);
        $runner->method('supports')->willReturn(true);
        $runner->method('run')->willReturn(new CheckResultDto(CheckStatus::Up, 42, 200));

        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects(self::atLeastOnce())->method('persist');
        $entityManager->expects(self::once())->method('flush');

        $service = $this->checkExecutorService([$runner], $entityManager, ['day']);

        $result = $service->execute($monitor);

        self::assertSame(CheckStatus::Up, $result->getStatus());
        self::assertSame(42, $result->getLatencyMs());
        self::assertNotNull($monitor->getNextCheckAt());
    }

    public function testExecuteReturnsUnknownWhenNoRunnerMatches(): void
    {
        $tenant  = new Tenant('main', 'Main');
        $monitor = new Monitor($tenant, 'X', MonitorType::Ping, '8.8.8.8');

        $runner = $this->createMock(CheckRunnerInterface::class);
        $runner->method('supports')->willReturn(false);

        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects(self::once())->method('persist');
        $entityManager->expects(self::once())->method('flush');

        $service = $this->checkExecutorService([$runner], $entityManager);

        $result = $service->execute($monitor);

        self::assertSame(CheckStatus::Unknown, $result->getStatus());
        self::assertStringContainsString('No check runner', (string) $result->getMessage());
    }

    public function testExecuteAppliesMinimumLatencyFloor(): void
    {
        $tenant  = new Tenant('main', 'Main');
        $monitor = new Monitor($tenant, 'API', MonitorType::Https, 'https://example.test');

        $runner = $this->createMock(CheckRunnerInterface::class);
        $runner->method('supports')->willReturn(true);
        $runner->method('run')->willReturn(new CheckResultDto(CheckStatus::Up, 0, 200));

        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects(self::atLeastOnce())->method('persist');
        $entityManager->expects(self::once())->method('flush');

        $service = $this->checkExecutorService([$runner], $entityManager, [], 3);

        self::assertSame(3, $service->execute($monitor)->getLatencyMs());
    }
}
