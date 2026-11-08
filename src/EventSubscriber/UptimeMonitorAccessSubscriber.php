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
use Nowo\UptimeMonitorBundle\Controller\SummaryController;
use Nowo\UptimeMonitorBundle\Controller\TenantController;
use Nowo\UptimeMonitorBundle\Entity\Monitor;
use Nowo\UptimeMonitorBundle\Repository\MonitorRepository;
use Nowo\UptimeMonitorBundle\Security\MonitorAwareUptimeMonitorAccessCheckerInterface;
use Nowo\UptimeMonitorBundle\Security\TenantAwareUptimeMonitorAccessCheckerInterface;
use Nowo\UptimeMonitorBundle\Security\UptimeMonitorAccessCheckerInterface;
use stdClass;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\ControllerEvent;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

use function in_array;
use function is_array;
use function is_numeric;
use function is_object;
use function is_string;

/**
 * Enforces UptimeMonitorAccessCheckerInterface on admin/manage controllers (REQ-UI-002).
 *
 * Public {@see StatusPageController} is excluded. When allow_unauthenticated is true, checks are skipped.
 * Tenant creation requires canManageSettings; any other unsafe-method request (POST/PUT/PATCH/DELETE) to a
 * protected action that is not explicitly mapped fails closed to canManageSettings as well. Since 1.21 a
 * {@see MonitorAwareUptimeMonitorAccessCheckerInterface} decides the reads of one monitor (page, history and aggregates
 * APIs) with canViewMonitor; its refusal answers 404. Since 1.23 `security.denied_tenant_status: 404` makes any refusal
 * on a `{tenantSlug}` route of a user the tenant-aware checker keeps out of that tenant (canAccessTenant false) a 404
 * too, so the tenant's existence is not revealed (UM-7); a member refused one action keeps 403.
 */
final readonly class UptimeMonitorAccessSubscriber implements EventSubscriberInterface
{
    /** MonitorController methods that mutate state (CRUD / pause). */
    private const MONITOR_MUTATIONS = ['new', 'edit', 'delete', 'togglePause', 'pushToken', 'pushRevoke'];

    /** Read actions of one monitor (`{id}`): controller class => method. */
    private const MONITOR_READS = [
        MonitorController::class       => 'show',
        HistoryApiController::class    => 'monitorHistory',
        AggregatesApiController::class => 'monitorAggregates',
    ];

    /** TenantController methods that mutate state (tenant creation). */
    private const TENANT_MUTATIONS = ['new'];

    /** HTTP methods treated as read-only for the default (unmapped) branch. */
    private const SAFE_HTTP_METHODS = ['GET', 'HEAD', 'OPTIONS'];

    /**
     * Creates the subscriber with the configured access checker.
     *
     * @param bool $allowUnauthenticated When true the access checks are skipped (demo / trusted networks)
     * @param TokenStorageInterface|null $tokenStorage Current security token; null when SecurityBundle is not installed
     * @param bool $webUiEnabled `web_ui.enabled`: when false the admin pages answer 404 even if a host imported their routes
     * @param MonitorRepository|null $monitorRepository Loads the monitor of a monitor read for a monitor-aware checker (since 1.21)
     * @param int $deniedTenantStatus `security.denied_tenant_status` (since 1.23): 404 answers a refusal on a tenant the user may not access as not found
     */
    public function __construct(
        private UptimeMonitorAccessCheckerInterface $accessChecker,
        private bool $allowUnauthenticated,
        private ?TokenStorageInterface $tokenStorage = null,
        private bool $webUiEnabled = true,
        private ?MonitorRepository $monitorRepository = null,
        private int $deniedTenantStatus = 403,
    ) {
    }

    /**
     * Runs the access check on kernel.controller, once the bundle controller is known.
     *
     * @return array<string, array{0: string, 1: int}>
     */
    public static function getSubscribedEvents(): array
    {
        return [
            KernelEvents::CONTROLLER => ['onKernelController', 0],
        ];
    }

    /**
     * Denies access to bundle dashboard, monitor and settings actions the current user may not use.
     *
     * @throws AccessDeniedException When the access checker refuses the action
     * @throws NotFoundHttpException When a monitor-aware checker refuses a monitor read, or (denied_tenant_status 404) the user may not access the tenant
     */
    public function onKernelController(ControllerEvent $event): void
    {
        $controller = $event->getController();
        if (!is_array($controller)) {
            return;
        }

        $controllerObject = $controller[0];
        if (!is_object($controllerObject)) {
            return;
        }

        // SEC-004 N3: a disabled admin UI stays disabled when a host imports routes.yaml directly.
        if (!$this->webUiEnabled && $this->isAdminUiController($controllerObject)) {
            throw new NotFoundHttpException('The uptime admin UI is disabled (nowo_uptime_monitor.web_ui.enabled: false).');
        }

        if ($this->allowUnauthenticated) {
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
        $scoped = $checker instanceof TenantAwareUptimeMonitorAccessCheckerInterface && is_string($tenant) && $tenant !== '';
        if ($scoped && $checker instanceof MonitorAwareUptimeMonitorAccessCheckerInterface && (self::MONITOR_READS[$controllerObject::class] ?? null) === $method) {
            $this->checkMonitorRead($checker, $user, $tenant, $event->getRequest()->attributes->get('id'));

            return;
        }
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
            if ($scoped && $this->deniedTenantStatus === 404 && !$checker->canAccessTenant($user, $tenant)) {
                // UM-7: someone kept out of the tenant learns nothing about it, on HTML, JSON and API routes alike.
                throw new NotFoundHttpException('Not found.');
            }

            throw new AccessDeniedException('Access to Uptime Monitor admin is denied.');
        }
    }

    /**
     * A monitor read under a monitor-aware checker: the monitor of the tenant the user may view, else 404 (an unknown
     * monitor, one of another tenant and one the user may not view answer alike).
     *
     * @throws NotFoundHttpException When the user may not view the monitor
     */
    private function checkMonitorRead(MonitorAwareUptimeMonitorAccessCheckerInterface $checker, object $user, string $tenantSlug, mixed $id): void
    {
        $monitor = is_numeric($id) ? $this->monitorRepository?->find((int) $id) : null;
        if (!$monitor instanceof Monitor || $monitor->getTenant()->getSlug() !== $tenantSlug || !$checker->canViewMonitor($user, $monitor)) {
            throw new NotFoundHttpException('Monitor not found.');
        }
    }

    private function isAdminUiController(object $controller): bool
    {
        return $controller instanceof DashboardController
            || $controller instanceof MonitorController
            || $controller instanceof SettingsController
            || $controller instanceof SummaryController
            || $controller instanceof TenantController;
    }

    private function isProtectedController(object $controller): bool
    {
        return $controller instanceof DashboardController
            || $controller instanceof MonitorController
            || $controller instanceof SettingsController
            || $controller instanceof SummaryController
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
