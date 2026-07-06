<?php

declare(strict_types=1);

namespace Nowo\UptimeMonitorBundle\Twig;

use Nowo\UptimeMonitorBundle\Monitor\TenantSettings;
use Nowo\UptimeMonitorBundle\Repository\TenantRepository;
use Nowo\UptimeMonitorBundle\Ui\UiFramework;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\RequestStack;
use Twig\Extension\AbstractExtension;
use Twig\Extension\GlobalsInterface;

use function in_array;
use function is_string;

/**
 * Exposes UI framework globals to Twig (Bootstrap, Tailwind, or custom BEM).
 */
final class UptimeUiExtension extends AbstractExtension implements GlobalsInterface
{
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
    }

    public function getGlobals(): array
    {
        $framework = $this->resolveFramework();

        return [
            'uptime_layout'                 => (string) ($this->templatesConfig['layout'] ?? '@NowoUptimeMonitorBundle/layout.html.twig'),
            'uptime_ui_framework'           => $framework->value,
            'uptime_tabler_skip_cdn'        => (bool) ($this->uiConfig['tabler']['skip_cdn'] ?? false),
            'uptime_ui_bootstrap_css'       => (string) ($this->uiConfig['bootstrap']['css_url'] ?? ''),
            'uptime_ui_bootstrap_js'        => (string) ($this->uiConfig['bootstrap']['js_url'] ?? ''),
            'uptime_ui_tailwind_css'        => $this->uiConfig['tailwind']['css_url'] ?? null,
            'uptime_ui_tailwind_cdn_script' => (string) ($this->uiConfig['tailwind']['cdn_script'] ?? 'https://cdn.tailwindcss.com'),
            'uptime_theme'                  => $this->resolveTheme(),
        ];
    }

    private function resolveTheme(): string
    {
        $request = $this->requestStack->getCurrentRequest();
        if ($request === null) {
            return 'auto';
        }

        $slug = $request->attributes->get('tenantSlug');
        if (!is_string($slug) || $slug === '') {
            return 'auto';
        }

        $tenant = $this->tenantRepository->findOneBySlug($slug);
        if ($tenant === null) {
            return 'auto';
        }

        $theme = TenantSettings::from($tenant)->getTheme();

        return in_array($theme, ['light', 'dark', 'auto'], true) ? $theme : 'auto';
    }

    private function resolveFramework(): UiFramework
    {
        $global = UiFramework::fromString((string) ($this->uiConfig['framework'] ?? UiFramework::Tabler->value));

        $request = $this->requestStack->getCurrentRequest();
        if ($request === null) {
            return $global;
        }

        $slug = $request->attributes->get('tenantSlug');
        if (!is_string($slug) || $slug === '') {
            return $global;
        }

        $tenant = $this->tenantRepository->findOneBySlug($slug);
        if ($tenant === null) {
            return $global;
        }

        $override = TenantSettings::from($tenant)->getUiFrameworkOverride();
        if ($override === null) {
            return $global;
        }

        return $override;
    }
}
