<?php

declare(strict_types=1);

namespace Nowo\UptimeMonitorBundle\EventSubscriber;

use Nowo\UptimeMonitorBundle\Routing\TenantPaths;
use Nowo\UptimeMonitorBundle\Routing\UptimeMonitorRouteLoader;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\KernelEvents;

use function is_string;

/**
 * `web_ui.strict_tenant_paths` (since 1.21): a tenant is served only under the path its slug selects — a slug that
 * starts with a `tenant_paths` `slug_prefix` only under that entry's copy, any other slug only under the default
 * path. Any other combination answers 404 (e.g. `/projects/user-…/uptime` when `user-` tenants live under
 * `/uptime/{tenantSlug}`). Without the option the routes carry no marker and this subscriber does nothing.
 */
final readonly class TenantPathGuardSubscriber implements EventSubscriberInterface
{
    /**
     * Creates the guard.
     *
     * @param TenantPaths $tenantPaths The configured tenant path copies
     */
    public function __construct(
        private TenantPaths $tenantPaths = new TenantPaths(),
    ) {
    }

    /**
     * Runs after the router (priority 32) has matched the route.
     *
     * @return array<string, array{0: string, 1: int}>
     */
    public static function getSubscribedEvents(): array
    {
        return [
            KernelEvents::REQUEST => ['onKernelRequest', 8],
        ];
    }

    /**
     * Refuses a tenant route whose path copy is not the one of its slug.
     *
     * @throws NotFoundHttpException When the slug belongs to another tenant path
     */
    public function onKernelRequest(RequestEvent $event): void
    {
        $attributes = $event->getRequest()->attributes;
        $served     = $attributes->get(UptimeMonitorRouteLoader::TENANT_PATH_ATTRIBUTE);
        $slug       = $attributes->get('tenantSlug');
        if (!is_string($served) || !is_string($slug)) {
            return;
        }

        if (($this->tenantPaths->namePrefixFor($slug) ?? '') !== $served) {
            throw new NotFoundHttpException('This tenant is not served under this path.');
        }
    }
}
