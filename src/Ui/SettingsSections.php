<?php

declare(strict_types=1);

namespace Nowo\UptimeMonitorBundle\Ui;

use Symfony\Component\DependencyInjection\Attribute\Autowire;

use function array_key_first;
use function in_array;
use function is_array;

/**
 * The settings screens a host keeps (`web_ui.settings.sections`, since 1.13): the others leave the section tabs and
 * answer 404, so a host that owns some settings (notifications, appearance…) shows only the bundle's others. Since
 * 1.21 `web_ui.settings.sections_resolver` narrows the list per tenant. Configuration plus a stateless resolver: safe
 * in worker mode.
 */
final readonly class SettingsSections
{
    /**
     * Creates the section list from the processed `web_ui` configuration.
     *
     * @param array<string, mixed> $webUi Processed `web_ui` config
     * @param SettingsSectionsResolverInterface|null $resolver The per-tenant sections (`web_ui.settings.sections_resolver`, since 1.21)
     */
    public function __construct(
        #[Autowire('%nowo_uptime_monitor.web_ui%')]
        private array $webUi = [],
        private ?SettingsSectionsResolverInterface $resolver = null,
    ) {
    }

    /**
     * The kept sections, in the bundle order: id => route. With a tenant and a resolver, only those the resolver
     * returns for it.
     *
     * @return array<string, string>
     */
    public function all(?string $tenantSlug = null): array
    {
        $kept     = $this->webUi['settings']['sections'] ?? null;
        $sections = [];
        foreach (UptimeAreaNav::SETTINGS_SECTIONS as $id => $route) {
            if (!is_array($kept) || in_array($id, $kept, true)) {
                $sections[$id] = $route;
            }
        }
        if ($tenantSlug === null || !$this->resolver instanceof SettingsSectionsResolverInterface) {
            return $sections;
        }

        $forTenant = $this->resolver->sections($tenantSlug);
        foreach ($sections as $id => $route) {
            if (!in_array($id, $forTenant, true)) {
                unset($sections[$id]);
            }
        }

        return $sections;
    }

    /**
     * Whether the host keeps the section `$id` (for the tenant, when given).
     */
    public function has(string $id, ?string $tenantSlug = null): bool
    {
        return isset($this->all($tenantSlug)[$id]);
    }

    /**
     * The first kept section, where the settings index leads; null when the host keeps none.
     */
    public function first(?string $tenantSlug = null): ?string
    {
        return array_key_first($this->all($tenantSlug));
    }
}
