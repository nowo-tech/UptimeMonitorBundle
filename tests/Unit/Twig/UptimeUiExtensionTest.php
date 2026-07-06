<?php

declare(strict_types=1);

namespace Nowo\UptimeMonitorBundle\Tests\Unit\Twig;

use Nowo\UptimeMonitorBundle\Entity\Tenant;
use Nowo\UptimeMonitorBundle\Repository\TenantRepository;
use Nowo\UptimeMonitorBundle\Tests\Unit\Support\EntityIdTrait;
use Nowo\UptimeMonitorBundle\Twig\UptimeUiExtension;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * @covers \Nowo\UptimeMonitorBundle\Twig\UptimeUiExtension
 */
final class UptimeUiExtensionTest extends TestCase
{
    use EntityIdTrait;

    public function testGlobalsWithoutRequest(): void
    {
        $extension = new UptimeUiExtension(
            ['framework' => 'tabler', 'tabler' => ['skip_cdn' => true], 'bootstrap' => [], 'tailwind' => []],
            ['layout' => '@NowoUptimeMonitorBundle/layout.html.twig'],
            new RequestStack(),
            $this->createMock(TenantRepository::class),
        );

        self::assertSame([
            'uptime_layout'                 => '@NowoUptimeMonitorBundle/layout.html.twig',
            'uptime_ui_framework'           => 'tabler',
            'uptime_tabler_skip_cdn'        => true,
            'uptime_ui_bootstrap_css'       => '',
            'uptime_ui_bootstrap_js'        => '',
            'uptime_ui_tailwind_css'        => null,
            'uptime_ui_tailwind_cdn_script' => 'https://cdn.tailwindcss.com',
            'uptime_theme'                  => 'auto',
        ], $extension->getGlobals());
    }

    public function testGlobalsResolveTenantThemeAndFrameworkOverride(): void
    {
        $tenant = new Tenant('acme', 'Acme');
        $tenant->setSettings([
            'theme'        => 'dark',
            'ui_framework' => 'bootstrap',
        ]);
        $this->setEntityId($tenant, 1);

        $repository = $this->createMock(TenantRepository::class);
        $repository->method('findOneBySlug')->with('acme')->willReturn($tenant);

        $request = new Request([], [], ['tenantSlug' => 'acme']);
        $stack   = new RequestStack();
        $stack->push($request);

        $extension = new UptimeUiExtension(
            ['framework' => 'tabler', 'tabler' => ['skip_cdn' => false], 'bootstrap' => ['css_url' => 'css', 'js_url' => 'js'], 'tailwind' => []],
            ['layout' => '@App/layout.html.twig'],
            $stack,
            $repository,
        );

        $globals = $extension->getGlobals();

        self::assertSame('@App/layout.html.twig', $globals['uptime_layout']);
        self::assertSame('bootstrap', $globals['uptime_ui_framework']);
        self::assertSame('dark', $globals['uptime_theme']);
    }
}
