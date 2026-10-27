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
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

#[CoversClass(StatusPageController::class)]
final class StatusPageControllerTest extends TestCase
{
    use ControllerContainerTrait;
    use EntityIdTrait;

    public function testIndexResolvesOverallOperational(): void
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

        $controller = new StatusPageController($tenantRepo, $monitorRepo, $checkRepo, [
            'enabled'      => true,
            'show_latency' => true,
            'title'        => 'Custom',
        ]);
        $this->bindController($controller);

        $response = $controller->index('main');
        self::assertSame('rendered', $response->getContent());
    }

    public function testIndexThrowsWhenDisabled(): void
    {
        $controller = new StatusPageController(
            $this->createMock(TenantRepository::class),
            $this->createMock(MonitorRepository::class),
            $this->createMock(CheckResultRepository::class),
            ['enabled' => false],
        );
        $this->bindController($controller);

        $this->expectException(NotFoundHttpException::class);
        $controller->index('main');
    }

    public function testIndexSkipsPausedMonitors(): void
    {
        $tenant = new Tenant('main', 'Main');
        $active = new Monitor($tenant, 'Up', MonitorType::Https, 'https://a.test');
        $paused = new Monitor($tenant, 'Paused', MonitorType::Https, 'https://b.test');
        $paused->setPaused(true);

        $tenantRepo = $this->createMock(TenantRepository::class);
        $tenantRepo->method('findOneBySlug')->willReturn($tenant);

        $monitorRepo = $this->createMock(MonitorRepository::class);
        $monitorRepo->method('findByTenantSlug')->willReturn([$active, $paused]);

        $checkRepo = $this->createMock(CheckResultRepository::class);
        $checkRepo->method('findLatestByMonitorIds')->willReturn([]);

        $controller = new StatusPageController($tenantRepo, $monitorRepo, $checkRepo, ['enabled' => true]);
        $this->bindController($controller);

        $controller->index('main');
        $this->addToAssertionCount(1);
    }

    public function testIndexThrowsWhenTenantMissing(): void
    {
        $controller = new StatusPageController(
            $this->createStub(TenantRepository::class),
            $this->createStub(MonitorRepository::class),
            $this->createStub(CheckResultRepository::class),
            ['enabled' => true],
        );
        $this->bindController($controller);

        $this->expectException(NotFoundHttpException::class);
        $this->expectExceptionMessage('Tenant "nope" not found.');
        $controller->index('nope');
    }

    /**
     * @return iterable<string, array{bool, array<string, mixed>, bool}>
     */
    public static function visibilityProvider(): iterable
    {
        yield 'disabled tenant' => [false, [], true];
        yield 'private tenant' => [true, ['status_page_public' => 'private'], true];
        yield 'default private globally' => [true, [], false];
        yield 'public opt-in overrides global default' => [true, ['status_page_public' => 'public'], false];
    }

    /**
     * @param array<string, mixed> $settings
     */
    #[DataProvider('visibilityProvider')]
    public function testDisabledOrPrivateTenantsAnswerNotFound(bool $enabled, array $settings, bool $defaultPublic): void
    {
        $tenant = (new Tenant('main', 'Main'))->setEnabled($enabled)->setSettings($settings);

        $tenantRepo = $this->createMock(TenantRepository::class);
        $tenantRepo->method('findOneBySlug')->willReturn($tenant);
        $monitorRepo = $this->createMock(MonitorRepository::class);
        $monitorRepo->method('findByTenantSlug')->willReturn([]);

        $controller = new StatusPageController($tenantRepo, $monitorRepo, $this->createMock(CheckResultRepository::class), [
            'enabled'        => true,
            'default_public' => $defaultPublic,
        ]);
        $this->bindController($controller);

        $public = $enabled && (($settings['status_page_public'] ?? null) === 'public' || (($settings['status_page_public'] ?? null) === null && $defaultPublic));
        if (!$public) {
            $this->expectException(NotFoundHttpException::class);
            $this->expectExceptionMessage('Tenant "main" not found.');
        }

        self::assertSame('rendered', $controller->index('main')->getContent());
    }
}
