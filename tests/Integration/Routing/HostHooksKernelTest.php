<?php

declare(strict_types=1);

namespace Nowo\UptimeMonitorBundle\Tests\Integration\Routing;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Nowo\UptimeMonitorBundle\Entity\Monitor;
use Nowo\UptimeMonitorBundle\Entity\Tenant;
use Nowo\UptimeMonitorBundle\Enum\MonitorType;
use Nowo\UptimeMonitorBundle\Tests\Support\HostRoutingKernel;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\IgnoreDeprecations;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;

use function array_keys;
use function array_search;

/**
 * `web_ui.monitor_show` through a real kernel with TwigBundle registered before this bundle (the normal order): the
 * configured slots must reach the rendered page. The contract tests pass `monitor_layout` as render context, so they
 * cannot see a value lost between the configuration and the template.
 */
#[CoversNothing]
#[IgnoreDeprecations]
final class HostHooksKernelTest extends TestCase
{
    private const SLOTS = ['figures' => 'host/_figures.html.twig', 'charts' => 'host/_charts.html.twig', 'details' => 'host/_details.html.twig'];

    private ?HostRoutingKernel $kernel = null;

    protected function tearDown(): void
    {
        $this->kernel?->shutdown();
        $this->kernel = null;
    }

    /**
     * @param array<string, mixed> $monitorShow
     */
    private function monitorPage(array $monitorShow): string
    {
        $this->kernel = new HostRoutingKernel(true, $monitorShow === [] ? [] : ['monitor_show' => $monitorShow]);
        $this->kernel->boot();

        $bundles = array_keys($this->kernel->getBundles());
        self::assertLessThan(array_search('UptimeMonitorBundle', $bundles, true), array_search('TwigBundle', $bundles, true), 'TwigBundle loads first');

        $em = $this->kernel->getContainer()->get('doctrine.orm.entity_manager');
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        (new SchemaTool($em))->createSchema($em->getMetadataFactory()->getAllMetadata());
        $tenant  = new Tenant('main', 'Main');
        $monitor = new Monitor($tenant, 'API health', MonitorType::Https, 'https://user:placeholder@api.test/');
        $em->persist($tenant);
        $em->persist($monitor);
        $em->flush();

        $response = $this->kernel->handle(Request::create('/uptime/main/monitors/' . $monitor->getId()));
        self::assertSame(200, $response->getStatusCode());

        return (string) $response->getContent();
    }

    public function testTheConfiguredSlotsAreRendered(): void
    {
        $html = $this->monitorPage(['charts' => 'host', 'slots' => self::SLOTS]);

        self::assertStringContainsString('<p class="host-details-slot">API health ', $html);
        self::assertStringContainsString('<p class="host-figures-slot">', $html);
        self::assertStringContainsString('<p class="host-charts-slot">/api/uptime/main/monitors/', $html);
        self::assertStringNotContainsString('uptime-metrics-cards', $html);
        self::assertStringNotContainsString('<canvas', $html);
        self::assertStringNotContainsString('placeholder', $html, 'monitor_safe.target is masked');
    }

    public function testChartsNoneReachesThePage(): void
    {
        $html = $this->monitorPage(['charts' => 'none', 'slots' => ['charts' => self::SLOTS['charts']]]);

        self::assertStringNotContainsString('uptime-detail-charts', $html);
        self::assertStringNotContainsString('host-charts-slot', $html);
        self::assertStringContainsString('uptime-metrics-cards', $html);
    }

    public function testWithoutConfigurationTheBundleRegionsRender(): void
    {
        $html = $this->monitorPage([]);

        self::assertStringContainsString('uptime-detail-charts', $html);
        self::assertStringContainsString('uptime-metrics-cards', $html);
        self::assertStringNotContainsString('host-details-slot', $html);
    }
}
