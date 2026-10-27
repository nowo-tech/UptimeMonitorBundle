<?php

declare(strict_types=1);

namespace Nowo\UptimeMonitorBundle\Ui;

use Symfony\Component\DependencyInjection\Attribute\Autowire;

use function array_key_first;
use function in_array;
use function is_array;

/**
 * The settings screens a host keeps (`web_ui.settings.sections`, since 1.13): the others leave the section tabs and
 * answer 404, so a host that owns some settings (notifications, appearance…) shows only the bundle's others.
 * Configuration only: safe in worker mode.
 */
final readonly class SettingsSections
{
    /**
     * Creates the section list from the processed `web_ui` configuration.
     *
     * @param array<string, mixed> $webUi Processed `web_ui` config
     */
    public function __construct(
        #[Autowire('%nowo_uptime_monitor.web_ui%')]
        private array $webUi = [],
    ) {
    }

    /**
     * The kept sections, in the bundle order: id => route.
     *
     * @return array<string, string>
     */
    public function all(): array
    {
        $kept = $this->webUi['settings']['sections'] ?? null;
        if (!is_array($kept)) {
            return UptimeAreaNav::SETTINGS_SECTIONS;
        }

        $sections = [];
        foreach (UptimeAreaNav::SETTINGS_SECTIONS as $id => $route) {
            if (in_array($id, $kept, true)) {
                $sections[$id] = $route;
            }
        }

        return $sections;
    }

    /**
     * Whether the host keeps the section `$id`.
     */
    public function has(string $id): bool
    {
        return isset($this->all()[$id]);
    }

    /**
     * The first kept section, where the settings index leads; null when the host keeps none.
     */
    public function first(): ?string
    {
        return array_key_first($this->all());
    }
}
