<?php

declare(strict_types=1);

namespace Nowo\UptimeMonitorBundle\Routing;

use Symfony\Component\DependencyInjection\Attribute\AsDecorator;
use Symfony\Component\DependencyInjection\Attribute\AutowireDecorated;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\CacheWarmer\WarmableInterface;
use Symfony\Component\Routing\Matcher\RequestMatcherInterface;
use Symfony\Component\Routing\RequestContext;
use Symfony\Component\Routing\Route;
use Symfony\Component\Routing\RouteCollection;
use Symfony\Component\Routing\RouterInterface;

use function is_string;
use function str_starts_with;

/**
 * `dashboard.tenant_paths`: a link to a tenant page (`nowo_uptime_*` with a `tenantSlug`) of a slug a tenant path
 * claims goes to that path's copy of the route, so templates keep generating `nowo_uptime_*` whatever the tenant.
 * Everything else is the router's.
 */
// A host without a `router` service (console-only kernels) keeps no decorator.
#[AsDecorator(decorates: 'router', onInvalid: ContainerInterface::IGNORE_ON_INVALID_REFERENCE)]
final readonly class TenantPathUrlGenerator implements RouterInterface, RequestMatcherInterface, WarmableInterface
{
    public function __construct(
        #[AutowireDecorated]
        private RouterInterface&RequestMatcherInterface&WarmableInterface $inner,
        private TenantPaths $tenantPaths,
    ) {
    }

    /**
     * @param array<string, mixed> $parameters
     */
    public function generate(string $name, array $parameters = [], int $referenceType = self::ABSOLUTE_PATH): string
    {
        $slug   = $parameters['tenantSlug'] ?? null;
        $prefix = is_string($slug) && str_starts_with($name, 'nowo_uptime_') ? $this->tenantPaths->namePrefixFor($slug) : null;
        if ($prefix !== null && $this->inner->getRouteCollection()->get($prefix . $name) instanceof Route) {
            $name = $prefix . $name;
        }

        return $this->inner->generate($name, $parameters, $referenceType);
    }

    public function setContext(RequestContext $context): void
    {
        // @igor-ignore - The router contract: Symfony sets the request context on the router each request.
        $this->inner->setContext($context);
    }

    public function getContext(): RequestContext
    {
        return $this->inner->getContext();
    }

    public function getRouteCollection(): RouteCollection
    {
        return $this->inner->getRouteCollection();
    }

    /**
     * @return array<string, mixed>
     */
    public function match(string $pathinfo): array
    {
        return $this->inner->match($pathinfo);
    }

    /**
     * @return array<string, mixed>
     */
    public function matchRequest(Request $request): array
    {
        return $this->inner->matchRequest($request);
    }

    public function warmUp(string $cacheDir, ?string $buildDir = null): array
    {
        return $this->inner->warmUp($cacheDir, $buildDir);
    }
}
