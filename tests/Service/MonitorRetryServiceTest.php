<?php

declare(strict_types=1);

namespace Nowo\UptimeMonitorBundle\Tests\Service;

use Nowo\UptimeMonitorBundle\Entity\Monitor;
use Nowo\UptimeMonitorBundle\Entity\Tenant;
use Nowo\UptimeMonitorBundle\Enum\CheckStatus;
use Nowo\UptimeMonitorBundle\Enum\MonitorType;
use Nowo\UptimeMonitorBundle\Model\CheckResultDto;
use Nowo\UptimeMonitorBundle\Service\MonitorRetryService;
use PHPUnit\Framework\TestCase;

final class MonitorRetryServiceTest extends TestCase
{
    private MonitorRetryService $service;

    protected function setUp(): void
    {
        $this->service = new MonitorRetryService();
    }

    public function testRetriesKeepPreviousStatusUntilThreshold(): void
    {
        $tenant  = new Tenant('demo', 'demo');
        $monitor = new Monitor($tenant, 'api', MonitorType::Http, 'https://example.test');
        $monitor->setRetries(2)->setLastKnownStatus(CheckStatus::Up);

        $fail = new CheckResultDto(CheckStatus::Down, 10, 500, 'error');

        $first = $this->service->normalizeResult($monitor, $fail);
        self::assertSame(CheckStatus::Up, $first->status);

        $second = $this->service->normalizeResult($monitor, $fail);
        self::assertSame(CheckStatus::Up, $second->status);

        $third = $this->service->normalizeResult($monitor, $fail);
        self::assertSame(CheckStatus::Down, $third->status);
    }

    public function testUpsideDownInvertsFailure(): void
    {
        $tenant  = new Tenant('demo', 'demo');
        $monitor = new Monitor($tenant, 'invert', MonitorType::Http, 'https://example.test');
        $monitor->setConfig(['upside_down' => true]);

        $fail   = new CheckResultDto(CheckStatus::Down, 5, null, 'timeout');
        $result = $this->service->normalizeResult($monitor, $fail);

        self::assertSame(CheckStatus::Up, $result->status);
    }
}
