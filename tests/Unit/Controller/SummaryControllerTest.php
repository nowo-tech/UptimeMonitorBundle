<?php

declare(strict_types=1);

namespace Nowo\UptimeMonitorBundle\Tests\Unit\Controller;

use Nowo\UiKitBundle\View\DashboardPage;
use Nowo\UiKitBundle\View\EmptyState;
use Nowo\UptimeMonitorBundle\Controller\SummaryController;
use Nowo\UptimeMonitorBundle\Entity\CheckResult;
use Nowo\UptimeMonitorBundle\Entity\Monitor;
use Nowo\UptimeMonitorBundle\Entity\Tenant;
use Nowo\UptimeMonitorBundle\Enum\CheckStatus;
use Nowo\UptimeMonitorBundle\Enum\MonitorType;
use Nowo\UptimeMonitorBundle\Repository\CheckResultRepository;
use Nowo\UptimeMonitorBundle\Repository\MonitorRepository;
use Nowo\UptimeMonitorBundle\Repository\TenantRepository;
use Nowo\UptimeMonitorBundle\Service\StatusSummary;
use Nowo\UptimeMonitorBundle\Tests\Unit\Support\ControllerContainerTrait;
use Nowo\UptimeMonitorBundle\Tests\Unit\Support\EntityIdTrait;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

#[CoversClass(SummaryController::class)]
final class SummaryControllerTest extends TestCase
{
    use ControllerContainerTrait;
    use EntityIdTrait;

    /**
     * @param array<string, mixed> $statusPage
     */
    private function controller(?Tenant $tenant, array $statusPage = []): SummaryController
    {
        $tenants = $this->createStub(TenantRepository::class);
        $tenants->method('findOneBySlug')->willReturn($tenant);
        $api = new Monitor($tenant ?? new Tenant('main', 'Main'), 'API', MonitorType::Https, 'https://x.test');
        $this->setEntityId($api, 1);
        $monitors = $this->createStub(MonitorRepository::class);
        $monitors->method('findByTenantSlug')->willReturn([$api]);
        $checks = $this->createStub(CheckResultRepository::class);
        $checks->method('findLatestByMonitorIds')->willReturn([1 => new CheckResult($api, CheckStatus::Down, 5)]);

        return new SummaryController($tenants, new StatusSummary($monitors, $checks), $statusPage);
    }

    public function testTheTenantAtAGlanceWithItsPublicPage(): void
    {
        $controller = $this->controller(new Tenant('main', 'Main'));
        $this->bindController($controller);

        self::assertSame('rendered', $controller->index('main')->getContent());
        self::assertSame('major', $this->renderedContext['overall_status']);
        self::assertSame(['down' => 1, 'degraded' => 0, 'unknown' => 0, 'up' => 0], $this->renderedContext['counts']);
        self::assertSame('/generated/nowo_uptime_status_page', $this->renderedContext['public_url']);
        self::assertInstanceOf(EmptyState::class, $this->renderedContext['summary_empty']);
        $page = $this->renderedContext['page'];
        self::assertInstanceOf(DashboardPage::class, $page);
        self::assertSame('uptime-summary-public', $page->header->actions[0]->attributes['data-testid']);
    }

    public function testNoPublicPageLinkWhenItIsPrivateOrOff(): void
    {
        $private = new Tenant('main', 'Main');
        $private->setSettings(['status_page_public' => 'private']);
        foreach ([[$private, []], [new Tenant('main', 'Main'), ['enabled' => false]], [new Tenant('main', 'Main'), ['default_public' => false]]] as [$tenant, $config]) {
            $controller = $this->controller($tenant, $config);
            $this->bindController($controller);

            $controller->index('main');

            self::assertNull($this->renderedContext['public_url']);
            self::assertSame([], $this->renderedContext['page']->header->actions);
        }
    }

    public function testUnknownTenantIsNotFound(): void
    {
        $controller = $this->controller(null);
        $this->bindController($controller);

        $this->expectException(NotFoundHttpException::class);
        $controller->index('nope');
    }
}
