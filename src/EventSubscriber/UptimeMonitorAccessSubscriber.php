<?php

declare(strict_types=1);

namespace Nowo\UptimeMonitorBundle\EventSubscriber;

use Nowo\UptimeMonitorBundle\Controller\Api\AggregatesApiController;
use Nowo\UptimeMonitorBundle\Controller\Api\HistoryApiController;
use Nowo\UptimeMonitorBundle\Controller\Api\StatusApiController;
use Nowo\UptimeMonitorBundle\Controller\DashboardController;
use Nowo\UptimeMonitorBundle\Controller\MonitorController;
use Nowo\UptimeMonitorBundle\Controller\SettingsController;
use Nowo\UptimeMonitorBundle\Controller\StatusPageController;
use Nowo\UptimeMonitorBundle\Controller\TenantController;
use Nowo\UptimeMonitorBundle\Security\TenantAwareUptimeMonitorAccessCheckerInterface;
use Nowo\UptimeMonitorBundle\Security\UptimeMonitorAccessCheckerInterface;
use stdClass;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\ControllerEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

use function in_array;
use function is_array;
use function is_object;
use function is_string;

/**
 * Enforces UptimeMonitorAccessCheckerInterface on admin/manage controllers (REQ-UI-002).
 *
 * Public {@see StatusPageController} is excluded. When allow_unauthenticated is true, checks are skipped.
 * Tenant creation requires canManageSettings; any other unsafe-method request (POST/PUT/PATCH/DELETE) to a
 * protected action that is not explicitly mapped fails closed to canManageSettings as well.
 */
final readonly class UptimeMonitorAccessSubscriber implements EventSubscriberInterface
{
    /** MonitorController methods that mutate state (CRUD / pause). */
    private const MONITOR_MUTATIONS = ['new', 'edit', 'delete', 'togglePause'];

    /** TenantController methods that mutate state (tenant creation). */
    private const TENANT_MUTATIONS = ['new'];

    /** HTTP methods treated as read-only for the default (unmapped) branch. */
    private const SAFE_HTTP_METHODS = ['GET', 'HEAD', 'OPTIONS'];

    public function __construct(
        private UptimeMonitorAccessCheckerInterface $accessChecker,
        private bool $allowUnauthenticated,
        private ?TokenStorageInterface $tokenStorage = null,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            KernelEvents::CONTROLLER => ['onKernelController', 0],
        ];
    }

    public function onKernelController(ControllerEvent $event): void
    {
        if ($this->allowUnauthenticated) {
            return;
        }

        $controller = $event->getController();
        if (!is_array($controller)) {
            return;
        }

        $controllerObject = $controller[0];
        if (!is_object($controllerObject)) {
            return;
        }

        $method = $controller[1];

        if ($controllerObject instanceof StatusPageController) {
            return;
        }

        if (!$this->isProtectedController($controllerObject)) {
            return;
        }

        $user    = $this->resolveUser();
        $tenant  = $event->getRequest()->attributes->get('tenantSlug');
        $checker = $this->accessChecker;
        // Since 1.9: a tenant-aware checker decides every request that names a tenant for that tenant.
        $scoped  = $checker instanceof TenantAwareUptimeMonitorAccessCheckerInterface && is_string($tenant) && $tenant !== '';
        $allowed = match (true) {
            $controllerObject instanceof SettingsController => $scoped
                ? $checker->canManageTenantSettings($user, $tenant)
                : $checker->canManageSettings($user),
            $controllerObject instanceof MonitorController => match (true) {
                $this->isMonitorMutation($method) && $scoped => $checker->canManageTenantMonitors($user, $tenant),
                $this->isMonitorMutation($method)            => $checker->canManageMonitors($user),
                $scoped                                      => $checker->canAccessTenant($user, $tenant),
                default                                      => $checker->canAccessDashboard($user),
            },
            $controllerObject instanceof TenantController && in_array($method, self::TENANT_MUTATIONS, true) => $checker->canManageSettings($user),
            !in_array($event->getRequest()->getMethod(), self::SAFE_HTTP_METHODS, true)                      => $scoped
                ? $checker->canManageTenantSettings($user, $tenant)
                : $checker->canManageSettings($user),
            $scoped => $checker->canAccessTenant($user, $tenant),
            default => $checker->canAccessDashboard($user),
        };

        if (!$allowed) {
            throw new AccessDeniedException('Access to Uptime Monitor admin is denied.');
        }
    }

    private function isProtectedController(object $controller): bool
    {
        return $controller instanceof DashboardController
            || $controller instanceof MonitorController
            || $controller instanceof SettingsController
            || $controller instanceof TenantController
            || $controller instanceof StatusApiController
            || $controller instanceof HistoryApiController
            || $controller instanceof AggregatesApiController;
    }

    private function isMonitorMutation(string $method): bool
    {
        return in_array($method, self::MONITOR_MUTATIONS, true);
    }

    private function resolveUser(): object
    {
        $user = $this->tokenStorage?->getToken()?->getUser();

        return is_object($user) ? $user : new stdClass();
    }
}
