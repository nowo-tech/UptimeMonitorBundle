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
use Twig\Environment;
use Twig\Loader\ArrayLoader;
use Twig\TwigFunction;

use function array_map;
use function crc32;
use function ucfirst;

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
            'nowo_uptime_layout_template'   => '@NowoUptimeMonitorBundle/layout.html.twig',
            'uptime_ui_framework'           => 'tabler',
            'uptime_tabler_skip_cdn'        => true,
            'uptime_ui_bootstrap_css'       => '',
            'uptime_ui_bootstrap_js'        => '',
            'uptime_ui_tailwind_css'        => null,
            'uptime_ui_tailwind_cdn_script' => 'https://cdn.tailwindcss.com',
            'uptime_theme'                  => 'auto',
        ], $extension->getGlobals());
    }

    public function testGlobalsStayRequestIndependentWhileFunctionsResolveTenant(): void
    {
        $repository = $this->createMock(TenantRepository::class);
        $repository->method('findOneBySlug')->with('acme')->willReturn($this->tenant('acme', 'dark', 'bootstrap'));

        $stack = new RequestStack();
        $stack->push(new Request([], [], ['tenantSlug' => 'acme']));

        $extension = new UptimeUiExtension(
            ['framework' => 'tabler', 'tabler' => ['skip_cdn' => false], 'bootstrap' => ['css_url' => 'css', 'js_url' => 'js'], 'tailwind' => []],
            ['layout' => '@App/layout.html.twig'],
            $stack,
            $repository,
        );

        $globals = $extension->getGlobals();

        self::assertSame('@App/layout.html.twig', $globals['uptime_layout']);
        self::assertSame('@App/layout.html.twig', $globals['nowo_uptime_layout_template']);
        self::assertSame('tabler', $globals['uptime_ui_framework']);
        self::assertSame('auto', $globals['uptime_theme']);
        self::assertSame('bootstrap', $extension->getUiFramework());
        self::assertSame('dark', $extension->getTheme());
    }

    public function testFunctionsAreRegistered(): void
    {
        $extension = new UptimeUiExtension([], [], new RequestStack(), $this->createMock(TenantRepository::class));

        $names = array_map(static fn (TwigFunction $function): string => $function->getName(), $extension->getFunctions());

        self::assertSame(['uptime_ui_framework', 'uptime_theme'], $names);
        self::assertSame('tabler', $extension->getUiFramework());
        self::assertSame('auto', $extension->getTheme());
    }

    public function testConsecutiveRequestsWithoutResetResolveEachTenant(): void
    {
        $repository = $this->createMock(TenantRepository::class);
        $repository->expects(self::exactly(3))->method('findOneBySlug')->willReturnMap([
            ['acme', $this->tenant('acme', 'dark', 'bootstrap')],
            ['globex', $this->tenant('globex', 'light', 'tailwind')],
            ['missing', null],
        ]);

        $stack     = new RequestStack();
        $extension = new UptimeUiExtension(['framework' => 'tabler'], [], $stack, $repository);
        $env       = new Environment(new ArrayLoader([
            'page' => '{{ uptime_ui_framework }}|{{ uptime_theme }}|{{ uptime_ui_framework() }}|{{ uptime_theme() }}|{{ uptime_ui_framework() }}',
        ]));
        $env->addExtension($extension);

        $stack->push(new Request([], [], ['tenantSlug' => 'acme']));
        self::assertSame('tabler|auto|bootstrap|dark|bootstrap', $env->render('page'));
        $stack->pop();

        $stack->push(new Request([], [], ['tenantSlug' => 'globex']));
        self::assertSame('tabler|auto|tailwind|light|tailwind', $env->render('page'));
        $stack->pop();

        $stack->push(new Request([], [], ['tenantSlug' => 'missing']));
        self::assertSame('tabler|auto|tabler|auto|tabler', $env->render('page'));
        $stack->pop();

        $stack->push(new Request());
        self::assertSame('tabler|auto|tabler|auto|tabler', $env->render('page'));
    }

    public function testInvalidThemeFallsBackToAutoAndResetClearsMemo(): void
    {
        $repository = $this->createMock(TenantRepository::class);
        $repository->expects(self::exactly(2))->method('findOneBySlug')->with('acme')
            ->willReturn($this->tenant('acme', 'neon', null));

        $stack = new RequestStack();
        $stack->push(new Request([], [], ['tenantSlug' => 'acme']));
        $extension = new UptimeUiExtension(['framework' => 'bootstrap'], [], $stack, $repository);

        self::assertSame('auto', $extension->getTheme());
        self::assertSame('bootstrap', $extension->getUiFramework());

        $extension->reset();

        self::assertSame('auto', $extension->getTheme());
    }

    private function tenant(string $slug, string $theme, ?string $framework): Tenant
    {
        $tenant   = new Tenant($slug, ucfirst($slug));
        $settings = ['theme' => $theme];
        if ($framework !== null) {
            $settings['ui_framework'] = $framework;
        }
        $tenant->setSettings($settings);
        $this->setEntityId($tenant, crc32($slug));

        return $tenant;
    }
}
