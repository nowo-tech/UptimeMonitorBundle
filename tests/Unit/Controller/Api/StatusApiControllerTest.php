<?php

declare(strict_types=1);

namespace Nowo\UptimeMonitorBundle\Tests\Unit\Controller\Api;

use DateTimeInterface;
use Nowo\UptimeMonitorBundle\Controller\Api\StatusApiController;
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
use Nowo\UptimeMonitorBundle\Tests\Unit\Support\SyncDispatcherTestTrait;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * @covers \Nowo\UptimeMonitorBundle\Controller\Api\StatusApiController
 */
final class StatusApiControllerTest extends TestCase
{
    use ControllerContainerTrait;
    use EntityIdTrait;
    use SyncDispatcherTestTrait;

    public function testSummaryReturnsMonitorPayload(): void
    {
        $tenant  = new Tenant('main', 'Main');
        $monitor = new Monitor($tenant, 'API', MonitorType::Https, 'https://x.test');
        $this->setEntityId($monitor, 3);
        $latest = new CheckResult($monitor, CheckStatus::Up, 25, 200);

        $tenantRepo = $this->createMock(TenantRepository::class);
        $tenantRepo->method('findOneBySlug')->willReturn($tenant);

        $monitorRepo = $this->createMock(MonitorRepository::class);
        $monitorRepo->method('findByTenantSlug')->willReturn([$monitor]);

        $checkRepo = $this->createMock(CheckResultRepository::class);
        $checkRepo->method('findLatestByMonitorIds')->willReturn([3 => $latest]);

        $controller = new StatusApiController($tenantRepo, $this->summaryPayloadBuilder($monitorRepo, $checkRepo));
        $this->bindController($controller);

        $response = $controller->summary('main', new Request());
        $data     = json_decode((string) $response->getContent(), true);

        self::assertSame(Response::HTTP_OK, $response->getStatusCode());
        self::assertSame('main', $data['tenant']);
        self::assertCount(1, $data['monitors']);
    }

    public function testSummaryReturns404ForUnknownTenant(): void
    {
        $tenantRepo = $this->createMock(TenantRepository::class);
        $tenantRepo->method('findOneBySlug')->willReturn(null);

        $controller = new StatusApiController(
            $tenantRepo,
            $this->summaryPayloadBuilder(),
        );
        $this->bindController($controller);

        $response = $controller->summary('missing', new Request());
        self::assertSame(Response::HTTP_NOT_FOUND, $response->getStatusCode());
    }

    public function testSummaryFiltersBySince(): void
    {
        $tenant  = new Tenant('main', 'Main');
        $monitor = new Monitor($tenant, 'API', MonitorType::Https, 'https://x.test');
        $this->setEntityId($monitor, 1);
        $latest = new CheckResult($monitor, CheckStatus::Up, 10);

        $tenantRepo = $this->createMock(TenantRepository::class);
        $tenantRepo->method('findOneBySlug')->willReturn($tenant);

        $monitorRepo = $this->createMock(MonitorRepository::class);
        $monitorRepo->method('findByTenantSlug')->willReturn([$monitor]);

        $checkRepo = $this->createMock(CheckResultRepository::class);
        $checkRepo->method('findLatestByMonitorIds')->willReturn([1 => $latest]);

        $controller = new StatusApiController($tenantRepo, $this->summaryPayloadBuilder($monitorRepo, $checkRepo));
        $this->bindController($controller);

        $since    = $latest->getCheckedAt()->modify('+1 hour')->format(DateTimeInterface::ATOM);
        $response = $controller->summary('main', new Request(['since' => $since]));
        $data     = json_decode((string) $response->getContent(), true);

        self::assertSame([], $data['monitors']);
    }

    public function testSummaryIgnoresInvalidSince(): void
    {
        $tenant  = new Tenant('main', 'Main');
        $monitor = new Monitor($tenant, 'API', MonitorType::Https, 'https://x.test');
        $this->setEntityId($monitor, 1);

        $tenantRepo = $this->createMock(TenantRepository::class);
        $tenantRepo->method('findOneBySlug')->willReturn($tenant);

        $monitorRepo = $this->createMock(MonitorRepository::class);
        $monitorRepo->method('findByTenantSlug')->willReturn([$monitor]);

        $checkRepo = $this->createMock(CheckResultRepository::class);
        $checkRepo->method('findLatestByMonitorIds')->willReturn([]);

        $controller = new StatusApiController($tenantRepo, $this->summaryPayloadBuilder($monitorRepo, $checkRepo));
        $this->bindController($controller);

        $response = $controller->summary('main', new Request(['since' => 'not-a-date']));
        $data     = json_decode((string) $response->getContent(), true);

        self::assertNull($data['since']);
        self::assertCount(1, $data['monitors']);
    }
}
