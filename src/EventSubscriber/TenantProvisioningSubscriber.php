<?php

declare(strict_types=1);

namespace Nowo\UptimeMonitorBundle\EventSubscriber;

use Nowo\UptimeMonitorBundle\Controller\Api\AggregatesApiController;
use Nowo\UptimeMonitorBundle\Controller\Api\HistoryApiController;
use Nowo\UptimeMonitorBundle\Controller\Api\StatusApiController;
use Nowo\UptimeMonitorBundle\Controller\DashboardController;
use Nowo\UptimeMonitorBundle\Controller\MonitorController;
use Nowo\UptimeMonitorBundle\Controller\SettingsController;
use Nowo\UptimeMonitorBundle\Controller\SummaryController;
use Nowo\UptimeMonitorBundle\Entity\Tenant;
use Nowo\UptimeMonitorBundle\Form\TenantFormType;
use Nowo\UptimeMonitorBundle\Repository\TenantRepository;
use Nowo\UptimeMonitorBundle\Tenant\MissingTenantProvisionerInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\ControllerEvent;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\KernelEvents;

use function is_array;
use function is_string;
use function preg_match;

/**
 * `multi_tenant.provisioner` (since 1.21): before an admin page or API of a tenant slug without a tenant runs, the
 * host's {@see MissingTenantProvisionerInterface} creates it (e.g. the tenant of a project, on its first visit).
 * Priority -8: after {@see UptimeMonitorAccessSubscriber} (0), so only users the access checker let in reach it. A slug
 * outside {@see TenantFormType::SLUG_PATTERN} answers 404 and only a safe request (GET, HEAD) provisions: the CSRF token
 * of a POST is checked later, by the controller, so a POST never provisions and meets the missing tenant's 404. The
 * public status page and the push endpoint never provision. Without a provisioner this subscriber does nothing.
 */
final readonly class TenantProvisioningSubscriber implements EventSubscriberInterface
{
    /**
     * Creates the subscriber.
     *
     * @param TenantRepository $tenants Looks the slug up
     * @param MissingTenantProvisionerInterface|null $provisioner The host's provisioner; null = off
     */
    public function __construct(
        private TenantRepository $tenants,
        private ?MissingTenantProvisionerInterface $provisioner = null,
    ) {
    }

    /**
     * Runs on kernel.controller, after the access check.
     *
     * @return array<string, array{0: string, 1: int}>
     */
    public static function getSubscribedEvents(): array
    {
        return [
            KernelEvents::CONTROLLER => ['onKernelController', -8],
        ];
    }

    /**
     * Provisions the tenant of the request's slug when it has none.
     *
     * @throws NotFoundHttpException When the slug is not valid, or the provisioner returns no tenant of that slug
     */
    public function onKernelController(ControllerEvent $event): void
    {
        if (!$this->provisioner instanceof MissingTenantProvisionerInterface || !$event->isMainRequest()) {
            return;
        }
        $controller = $event->getController();
        $slug       = $event->getRequest()->attributes->get('tenantSlug');
        if (!is_array($controller) || !$this->isTenantController($controller[0]) || !is_string($slug) || $slug === '') {
            return;
        }
        if (preg_match(TenantFormType::SLUG_PATTERN, $slug) !== 1) {
            throw new NotFoundHttpException('Tenant not found.');
        }
        if (!$event->getRequest()->isMethodSafe() || $this->tenants->findOneBySlug($slug) instanceof Tenant) {
            return;
        }

        $tenant = $this->provisioner->provision($slug);
        if (!$tenant instanceof Tenant || $tenant->getSlug() !== $slug) {
            throw new NotFoundHttpException('Tenant not found.');
        }
    }

    private function isTenantController(mixed $controller): bool
    {
        return $controller instanceof DashboardController
            || $controller instanceof MonitorController
            || $controller instanceof SettingsController
            || $controller instanceof SummaryController
            || $controller instanceof StatusApiController
            || $controller instanceof HistoryApiController
            || $controller instanceof AggregatesApiController;
    }
}
