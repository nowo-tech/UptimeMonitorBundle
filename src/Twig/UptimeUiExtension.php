<?php

declare(strict_types=1);

namespace Nowo\UptimeMonitorBundle\Twig;

use Nowo\UptimeMonitorBundle\Entity\Tenant;
use Nowo\UptimeMonitorBundle\Monitor\TenantSettings;
use Nowo\UptimeMonitorBundle\Repository\TenantRepository;
use Nowo\UptimeMonitorBundle\Ui\UiFramework;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Contracts\Service\ResetInterface;
use Twig\Extension\AbstractExtension;
use Twig\Extension\GlobalsInterface;
use Twig\TwigFunction;
use WeakMap;

use function in_array;
use function is_string;

/**
 * Exposes UI framework globals and functions to Twig (Bootstrap, Tailwind, or custom BEM).
 *
 * Twig caches globals for the lifetime of the environment (a whole worker when the kernel is not reset),
 * so globals only carry request-independent configuration. Tenant-dependent values (theme and UI framework
 * override) are exposed through the `uptime_theme()` and `uptime_ui_framework()` functions, evaluated per call.
 */
final class UptimeUiExtension extends AbstractExtension implements GlobalsInterface, ResetInterface
{
    /** @var WeakMap<Request, array{framework: UiFramework, theme: string}> */
    private WeakMap $resolved;

    /**
     * @param array<string, mixed> $uiConfig
     * @param array<string, mixed> $templatesConfig
     */
    public function __construct(
        #[Autowire('%nowo_uptime_monitor.ui%')]
        private readonly array $uiConfig,
        #[Autowire('%nowo_uptime_monitor.templates%')]
        private readonly array $templatesConfig,
        private readonly RequestStack $requestStack,
        private readonly TenantRepository $tenantRepository,
    ) {
        $this->resolved = new WeakMap();
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('uptime_ui_framework', $this->getUiFramework(...)),
            new TwigFunction('uptime_theme', $this->getTheme(...)),
        ];
    }

    /**
     * Request-independent values only: `uptime_ui_framework` is the configured global framework and
     * `uptime_theme` is always `auto`. Use the functions of the same name for the per-tenant values.
     */
    public function getGlobals(): array
    {
        $layout = (string) ($this->templatesConfig['layout'] ?? '@NowoUptimeMonitorBundle/layout.html.twig');

        return [
            'uptime_layout' => $layout,
            // REQ-UI-001 alias (mirrors templates.layout / uptime_layout)
            'nowo_uptime_layout_template'   => $layout,
            'uptime_ui_framework'           => $this->globalFramework()->value,
            'uptime_tabler_skip_cdn'        => (bool) ($this->uiConfig['tabler']['skip_cdn'] ?? false),
            'uptime_ui_bootstrap_css'       => (string) ($this->uiConfig['bootstrap']['css_url'] ?? ''),
            'uptime_ui_bootstrap_js'        => (string) ($this->uiConfig['bootstrap']['js_url'] ?? ''),
            'uptime_ui_tailwind_css'        => $this->uiConfig['tailwind']['css_url'] ?? null,
            'uptime_ui_tailwind_cdn_script' => (string) ($this->uiConfig['tailwind']['cdn_script'] ?? 'https://cdn.tailwindcss.com'),
            'uptime_theme'                  => 'auto',
        ];
    }

    /**
     * UI framework for the current request (tenant override or global config).
     */
    public function getUiFramework(): string
    {
        return $this->resolve()['framework']->value;
    }

    /**
     * Theme (`light`, `dark` or `auto`) for the current request's tenant.
     */
    public function getTheme(): string
    {
        return $this->resolve()['theme'];
    }

    public function reset(): void
    {
        $this->resolved = new WeakMap();
    }

    /**
     * @return array{framework: UiFramework, theme: string}
     */
    private function resolve(): array
    {
        $request = $this->requestStack->getCurrentRequest();
        if (!$request instanceof Request) {
            return ['framework' => $this->globalFramework(), 'theme' => 'auto'];
        }

        return $this->resolved[$request] ??= $this->resolveForTenant($this->findTenant($request));
    }

    /**
     * @return array{framework: UiFramework, theme: string}
     */
    private function resolveForTenant(?Tenant $tenant): array
    {
        if (!$tenant instanceof Tenant) {
            return ['framework' => $this->globalFramework(), 'theme' => 'auto'];
        }

        $settings = TenantSettings::from($tenant);
        $theme    = $settings->getTheme();

        return [
            'framework' => $settings->getUiFrameworkOverride() ?? $this->globalFramework(),
            'theme'     => in_array($theme, ['light', 'dark', 'auto'], true) ? $theme : 'auto',
        ];
    }

    private function findTenant(Request $request): ?Tenant
    {
        $slug = $request->attributes->get('tenantSlug');
        if (!is_string($slug) || $slug === '') {
            return null;
        }

        return $this->tenantRepository->findOneBySlug($slug);
    }

    private function globalFramework(): UiFramework
    {
        return UiFramework::fromString((string) ($this->uiConfig['framework'] ?? UiFramework::Tabler->value));
    }
}
