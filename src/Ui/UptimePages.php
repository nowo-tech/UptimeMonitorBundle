<?php

declare(strict_types=1);

namespace Nowo\UptimeMonitorBundle\Ui;

use Nowo\UiKitBundle\View\Label;
use Nowo\UptimeMonitorBundle\DependencyInjection\Configuration;
use Nowo\UptimeMonitorBundle\Translation\UptimeTranslation;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Twig\Environment;

use function is_array;
use function is_string;
use function trigger_deprecation;

/**
 * Page plumbing of the UiKit page contract (REQ-UI-003): resolves the layout a scaffold extends
 * (`web_ui.layout_template`, null = `nowo_ui_kit.layouts.admin`; a deprecated 1.x `templates.layout` is wrapped by
 * {@see self::LEGACY_LAYOUT_BRIDGE}), the per-page template overrides (`web_ui.templates.<page>`) and the form themes
 * of the bundle forms. Configuration only: no request state, safe in worker mode.
 */
final readonly class UptimePages
{
    /** Bundle name of the page view models (theme exceptions `nowo_ui_kit.theme.bundles.NowoUptimeMonitorBundle`). */
    public const BUNDLE = 'NowoUptimeMonitorBundle';

    /** BC bridge around a 1.x layout that renders `{% block uptime_content %}` (removed in 2.0). */
    public const LEGACY_LAYOUT_BRIDGE = '@NowoUptimeMonitorBundle/_legacy_layout.html.twig';

    /**
     * @param array<string, mixed> $webUi processed `web_ui` config
     * @param array<string, mixed> $templates processed (deprecated) `templates` config
     */
    public function __construct(
        #[Autowire('%nowo_uptime_monitor.web_ui%')]
        private array $webUi = [],
        #[Autowire('%nowo_uptime_monitor.templates%')]
        private array $templates = [],
        #[Autowire('@?twig')]
        private ?Environment $twig = null,
    ) {
    }

    /**
     * Translation key of the bundle domain as a UiKit label.
     *
     * @param array<string, mixed> $params
     */
    public static function label(string $key, array $params = []): Label
    {
        return new Label($key, UptimeTranslation::DOMAIN, $params);
    }

    /**
     * Template of a page: the `web_ui.templates.<page>` override, else the bundle template.
     */
    public function template(string $page, string $default): string
    {
        $override = $this->webUi['templates'][$page] ?? null;

        return is_string($override) && $override !== '' ? $override : $default;
    }

    /**
     * Layout the admin scaffolds extend (`page.layout`): `web_ui.layout_template`, the legacy bridge for a 1.x layout,
     * else null (UiKit `nowo_ui_layout.admin`).
     */
    public function layout(): ?string
    {
        $layout = $this->webUi['layout_template'] ?? null;
        if (is_string($layout) && $layout !== '') {
            return $layout;
        }

        return $this->legacyLayout() !== null ? self::LEGACY_LAYOUT_BRIDGE : null;
    }

    /**
     * The 1.x layout (renders `uptime_content`) still configured through the deprecated `templates.layout`, or an
     * application override of the former default `@NowoUptimeMonitorBundle/layout.html.twig`; null when none.
     */
    public function legacyLayout(): ?string
    {
        $layout = $this->webUi['layout_template'] ?? null;
        if (is_string($layout) && $layout !== '') {
            return null;
        }

        $legacy = $this->templates['layout'] ?? Configuration::LEGACY_LAYOUT;
        if (!is_string($legacy) || $legacy === '') {
            return null;
        }

        if ($legacy === Configuration::LEGACY_LAYOUT && !($this->twig?->getLoader()->exists($legacy) ?? false)) {
            return null;
        }

        trigger_deprecation('nowo-tech/uptime-monitor-bundle', '1.11', 'The 1.x layout "%s" (block uptime_content) is wrapped in "%s". Render the UiKit canonical blocks in your layout and set nowo_uptime_monitor.web_ui.layout_template (null = nowo_ui_kit.layouts.admin); see docs/UPGRADING.md.', $legacy, self::LEGACY_LAYOUT_BRIDGE);

        return $legacy;
    }

    /**
     * Form themes of the bundle forms (`web_ui.form_themes`).
     *
     * @return list<string>
     */
    public function formThemes(): array
    {
        $themes = $this->webUi['form_themes'] ?? [Configuration::DEFAULT_FORM_THEME];
        if (!is_array($themes)) {
            return [];
        }

        $list = [];
        foreach ($themes as $theme) {
            if (is_string($theme) && $theme !== '') {
                $list[] = $theme;
            }
        }

        return $list;
    }

    /**
     * Shared variables of every bundle page (added to the controller variables).
     *
     * @param array<string, mixed> $vars page variables
     *
     * @return array<string, mixed>
     */
    public function context(array $vars): array
    {
        return $vars + [
            'nowo_uptime_legacy_layout' => $this->legacyLayout(),
            'uptime_form_themes'        => $this->formThemes(),
        ];
    }
}
