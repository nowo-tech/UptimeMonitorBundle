<?php

declare(strict_types=1);

namespace Nowo\UptimeMonitorBundle\Search;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\RequestStack;

use function is_string;

/**
 * Default tenant resolver of monitor search (since 1.32): the `tenantSlug` attribute of the current request when there
 * is one, else `multi_tenant.default_tenant`. Reads the request stack at call time: nothing is kept.
 */
final readonly class RequestMonitorSearchTenantResolver implements MonitorSearchTenantResolverInterface
{
    /**
     * @param array<string, mixed> $multiTenant `multi_tenant` configuration
     */
    public function __construct(
        private RequestStack $requests,
        #[Autowire('%nowo_uptime_monitor.multi_tenant%')]
        private array $multiTenant = [],
    ) {
    }

    /**
     * The `tenantSlug` of the current request, else `multi_tenant.default_tenant`; null when neither is set.
     */
    public function resolve(string $userIdentifier): ?string
    {
        $slug = $this->requests->getCurrentRequest()?->attributes->get('tenantSlug');
        if (is_string($slug) && $slug !== '') {
            return $slug;
        }

        $default = $this->multiTenant['default_tenant'] ?? 'main';

        return is_string($default) && $default !== '' ? $default : null;
    }
}
