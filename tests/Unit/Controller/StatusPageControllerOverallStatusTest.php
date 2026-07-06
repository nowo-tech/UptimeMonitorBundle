<?php

declare(strict_types=1);

namespace Nowo\UptimeMonitorBundle\Tests\Unit\Controller;

use Nowo\UptimeMonitorBundle\Controller\StatusPageController;
use Nowo\UptimeMonitorBundle\Entity\CheckResult;
use Nowo\UptimeMonitorBundle\Entity\Monitor;
use Nowo\UptimeMonitorBundle\Entity\Tenant;
use Nowo\UptimeMonitorBundle\Enum\CheckStatus;
use Nowo\UptimeMonitorBundle\Enum\MonitorType;
use Nowo\UptimeMonitorBundle\Repository\CheckResultRepository;
use Nowo\UptimeMonitorBundle\Repository\MonitorRepository;
use Nowo\UptimeMonitorBundle\Repository\TenantRepository;
use Nowo\UptimeMonitorBundle\Tests\Unit\Support\ControllerContainerTrait;
use Nowo\UptimeMonitorBundle\Tests\Unit\Support\EntityIdTrait;
use PHPUnit\Framework\TestCase;

/**
 * @covers \Nowo\UptimeMonitorBundle\Controller\StatusPageController
 */
final class StatusPageControllerOverallStatusTest extends TestCase
{
    use ControllerContainerTrait;
    use EntityIdTrait;

    /**
     * @param list<CheckStatus> $statuses
     * @param array<string, mixed> $config
     */
    private function renderWithStatuses(array $statuses, array $config = []): string
    {
        $tenant   = new Tenant('main', 'Main');
        $monitors = [];
        $latest   = [];

        foreach ($statuses as $index => $status) {
            $monitor = new Monitor($tenant, 'M' . $index, MonitorType::Https, 'https://x.test');
            $this->setEntityId($monitor, $index + 1);
            $monitors[]         = $monitor;
            $latest[$index + 1] = new CheckResult($monitor, $status, 10);
        }

        $tenantRepo = $this->createMock(TenantRepository::class);
        $tenantRepo->method('findOneBySlug')->willReturn($tenant);

        $monitorRepo = $this->createMock(MonitorRepository::class);
        $monitorRepo->method('findByTenantSlug')->willReturn($monitors);

        $checkRepo = $this->createMock(CheckResultRepository::class);
        $checkRepo->method('findLatestByMonitorIds')->willReturn($latest);

        $controller = new StatusPageController($tenantRepo, $monitorRepo, $checkRepo, $config + [
            'enabled'      => true,
            'show_latency' => false,
            'title'        => null,
        ]);
        $this->bindController($controller);

        return (string) $controller->index('main')->getContent();
    }

    public function testOverallStatusMajorWhenDownPresent(): void
    {
        self::assertSame('rendered', $this->renderWithStatuses([CheckStatus::Up, CheckStatus::Down]));
    }

    public function testOverallStatusDegraded(): void
    {
        self::assertSame('rendered', $this->renderWithStatuses([CheckStatus::Degraded]));
    }

    public function testOverallStatusUnknown(): void
    {
        self::assertSame('rendered', $this->renderWithStatuses([CheckStatus::Unknown]));
    }

    public function testOverallStatusOperational(): void
    {
        self::assertSame('rendered', $this->renderWithStatuses([CheckStatus::Up]));
    }

    public function testOverallStatusUnknownWhenNoRows(): void
    {
        self::assertSame('rendered', $this->renderWithStatuses([]));
    }
}
