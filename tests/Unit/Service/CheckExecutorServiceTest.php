<?php

declare(strict_types=1);

namespace Nowo\UptimeMonitorBundle\Tests\Unit\Service;

use Doctrine\ORM\EntityManagerInterface;
use LogicException;
use Nowo\UptimeMonitorBundle\Check\CheckRunnerInterface;
use Nowo\UptimeMonitorBundle\Entity\Monitor;
use Nowo\UptimeMonitorBundle\Entity\Tenant;
use Nowo\UptimeMonitorBundle\Enum\CheckStatus;
use Nowo\UptimeMonitorBundle\Enum\MonitorType;
use Nowo\UptimeMonitorBundle\Model\CheckResultDto;
use Nowo\UptimeMonitorBundle\Service\CheckExecutorService;
use Nowo\UptimeMonitorBundle\Tests\Unit\Support\SyncDispatcherTestTrait;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(CheckExecutorService::class)]
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

    public function testAThrowingRunnerIsRecordedAndTheMonitorRescheduled(): void
    {
        $monitor = new Monitor(new Tenant('main', 'Main'), 'X', MonitorType::Https, 'https://x.test');
        $monitor->setRetries(0);

        $runner = $this->createMock(CheckRunnerInterface::class);
        $runner->method('supports')->willReturn(true);
        $runner->method('run')->willThrowException(new LogicException('boom https://user:secret@x.test'));

        $result = $this->checkExecutorService([$runner], $this->createMock(EntityManagerInterface::class))->execute($monitor);

        self::assertSame(CheckStatus::Unknown, $result->getStatus());
        self::assertSame('Check failed (LogicException)', $result->getMessage());
        self::assertNotNull($monitor->getNextCheckAt());
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

    public function testChildCheckRefreshesItsActiveParentGroup(): void
    {
        $tenant = new Tenant('main', 'Main');
        $group  = new Monitor($tenant, 'Group', MonitorType::Group, 'Group');
        $child  = (new Monitor($tenant, 'API', MonitorType::Https, 'https://example.test'))->setParent($group);
        $paused = (new Monitor($tenant, 'Paused group', MonitorType::Group, 'P'))->setPaused(true);
        $orphan = (new Monitor($tenant, 'Other', MonitorType::Https, 'https://other.test'))->setParent($paused);

        $checked = [];
        $runner  = $this->createMock(CheckRunnerInterface::class);
        $runner->method('supports')->willReturn(true);
        $runner->method('run')->willReturnCallback(static function (Monitor $monitor) use (&$checked): CheckResultDto {
            $checked[] = $monitor->getName();

            return new CheckResultDto(CheckStatus::Up, 1);
        });

        $service = $this->checkExecutorService([$runner]);
        $service->execute($child);
        $service->execute($orphan);

        self::assertSame(['API', 'Group', 'Other'], $checked);
    }
}
