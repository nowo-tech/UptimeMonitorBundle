<?php

declare(strict_types=1);

namespace Nowo\UptimeMonitorBundle\Ui;

use Nowo\UiKitBundle\View\Action;
use Nowo\UiKitBundle\View\Confirm;
use Nowo\UptimeMonitorBundle\Controller\MonitorController;
use Nowo\UptimeMonitorBundle\Entity\Monitor;
use Nowo\UptimeMonitorBundle\Limits\TenantQuota;
use Nowo\UptimeMonitorBundle\Security\TenantAwareUptimeMonitorAccessCheckerInterface;
use Nowo\UptimeMonitorBundle\Security\UptimeMonitorAccessCheckerInterface;
use stdClass;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;

use function is_array;
use function is_object;

/**
 * The page actions of the admin UI as UiKit view models, each one only for users the access checker lets use it:
 * the dashboard header (`web_ui.dashboard.actions`), New monitor (disabled with the reason once the tenant reached
 * its {@see TenantQuota}) and a monitor's edit / pause or resume / delete (behind a confirm). POST actions carry the
 * CSRF ids {@see MonitorController} checks. Request data comes from the token
 * storage at call time: safe in worker mode.
 */
final readonly class UptimeActions
{
    /**
     * Creates the action builder.
     *
     * @param UptimeMonitorAccessCheckerInterface $accessChecker Access checker of the admin UI
     * @param UrlGeneratorInterface $urls URL generator (tenant path copies included)
     * @param TenantQuota $quota Monitors a tenant may still add
     * @param TokenStorageInterface|null $tokenStorage Token storage (null without SecurityBundle)
     * @param CsrfTokenManagerInterface|null $csrf CSRF token manager of the POST actions
     * @param bool $allowUnauthenticated `security.allow_unauthenticated`: every action for everyone
     * @param array<string, mixed> $webUi Processed `web_ui` config
     * @param array<string, mixed> $statusPage Processed `status_page` config
     */
    public function __construct(
        private UptimeMonitorAccessCheckerInterface $accessChecker,
        private UrlGeneratorInterface $urls,
        private TenantQuota $quota,
        #[Autowire('@?security.token_storage')]
        private ?TokenStorageInterface $tokenStorage = null,
        #[Autowire('@?security.csrf.token_manager')]
        private ?CsrfTokenManagerInterface $csrf = null,
        #[Autowire('%nowo_uptime_monitor.security.allow_unauthenticated%')]
        private bool $allowUnauthenticated = false,
        #[Autowire('%nowo_uptime_monitor.web_ui%')]
        private array $webUi = [],
        #[Autowire('%nowo_uptime_monitor.status_page%')]
        private array $statusPage = [],
    ) {
    }

    /**
     * Whether the current user may create, edit, pause or delete the tenant's monitors.
     */
    public function canManageMonitors(string $tenantSlug): bool
    {
        if ($this->allowUnauthenticated) {
            return true;
        }

        $checker = $this->accessChecker;

        return $checker instanceof TenantAwareUptimeMonitorAccessCheckerInterface
            ? $checker->canManageTenantMonitors($this->user(), $tenantSlug)
            : $checker->canManageMonitors($this->user());
    }

    /**
     * Whether the current user may change the tenant's settings.
     */
    public function canManageSettings(string $tenantSlug): bool
    {
        if ($this->allowUnauthenticated) {
            return true;
        }

        $checker = $this->accessChecker;

        return $checker instanceof TenantAwareUptimeMonitorAccessCheckerInterface
            ? $checker->canManageTenantSettings($this->user(), $tenantSlug)
            : $checker->canManageSettings($this->user());
    }

    /**
     * Dashboard header actions in the `web_ui.dashboard.actions` order: Settings (settings managers), Status page
     * (when the status page is enabled) and New monitor (monitor managers).
     *
     * @return list<Action>
     */
    public function dashboardHeader(string $tenantSlug): array
    {
        $configured = $this->webUi['dashboard']['actions'] ?? ['settings', 'status_page', 'new_monitor'];
        $actions    = [];
        foreach (is_array($configured) ? $configured : [] as $key) {
            $action = match ($key) {
                'settings'    => $this->canManageSettings($tenantSlug) ? new Action('custom', UptimePages::label('dashboard.settings'), $this->urls->generate('nowo_uptime_settings_index', ['tenantSlug' => $tenantSlug]), icon: 'config', attributes: ['data-testid' => 'uptime-dashboard-settings']) : null,
                'status_page' => ($this->statusPage['enabled'] ?? true) ? new Action('view', UptimePages::label('dashboard.status_page'), $this->urls->generate('nowo_uptime_status_page', ['tenantSlug' => $tenantSlug]), attributes: ['data-testid' => 'uptime-dashboard-status-page']) : null,
                'new_monitor' => $this->newMonitor($tenantSlug),
                default       => null,
            };
            if ($action instanceof Action) {
                $actions[] = $action;
            }
        }

        return $actions;
    }

    /**
     * New monitor for monitor managers (opens the monitor form dialog; a plain link without JavaScript); disabled,
     * saying why, once the tenant holds as many monitors as its limit allows.
     */
    public function newMonitor(string $tenantSlug): ?Action
    {
        if (!$this->canManageMonitors($tenantSlug)) {
            return null;
        }

        $url = $this->urls->generate('nowo_uptime_monitor_new', ['tenantSlug' => $tenantSlug]);
        if (!$this->quota->canAdd($tenantSlug)) {
            return new Action(
                'create',
                UptimePages::label('monitor.new'),
                $url,
                variant: 'primary',
                attributes: ['data-testid' => 'uptime-monitor-new'],
                disabled: true,
                title: UptimePages::label('monitor.limits.max_monitors', ['%count%' => $this->quota->max($tenantSlug)]),
            );
        }

        return new Action('create', UptimePages::label('monitor.new'), $url, variant: 'primary', attributes: [
            'data-monitor-form-open'   => true,
            'data-monitor-form-url'    => $url,
            'data-monitor-form-return' => 'dashboard',
            'data-testid'              => 'uptime-monitor-new',
        ]);
    }

    /**
     * Edit, pause or resume, and delete (behind a confirm) of a monitor, for monitor managers; `$row` = the actions
     * of a table row (View first, short labels).
     *
     * @return list<Action>
     */
    public function monitor(string $tenantSlug, Monitor $monitor, bool $row = false): array
    {
        $id      = (int) $monitor->getId();
        $params  = ['tenantSlug' => $tenantSlug, 'id' => $id];
        $actions = $row ? [Action::view($this->urls->generate('nowo_uptime_monitor_show', $params), UptimePages::label('monitor.view'))] : [];
        if (!$this->canManageMonitors($tenantSlug)) {
            return $actions;
        }

        $editUrl   = $this->urls->generate('nowo_uptime_monitor_edit', $params);
        $actions[] = new Action('edit', UptimePages::label($row ? 'monitor.edit_short' : 'monitor.edit'), $editUrl, attributes: [
            'data-monitor-form-open'   => true,
            'data-monitor-form-url'    => $editUrl,
            'data-monitor-form-return' => $row ? 'dashboard' : 'show',
            'data-testid'              => 'uptime-monitor-edit',
        ]);
        $actions[] = new Action(
            $monitor->isPaused() ? 'restore' : 'custom',
            UptimePages::label($monitor->isPaused() ? 'monitor.resume' : 'monitor.pause'),
            // From the monitor page the controller returns to it (`?return=show`).
            $this->urls->generate('nowo_uptime_monitor_toggle_pause', $row ? $params : $params + ['return' => 'show']),
            method: 'POST',
            csrfToken: $this->token('toggle' . $id),
            attributes: ['data-testid' => 'uptime-monitor-pause'],
        );
        $actions[] = Action::delete(
            $this->urls->generate('nowo_uptime_monitor_delete', $params),
            $this->token('delete' . $id),
            new Confirm(
                UptimePages::label('monitor.delete'),
                UptimePages::label('monitor.delete_confirm', ['%name%' => $monitor->getName()]),
                UptimePages::label('monitor.delete'),
                id: 'uptime-monitor-delete-' . $id,
            ),
            UptimePages::label($row ? 'monitor.delete_short' : 'monitor.delete'),
        );

        return $actions;
    }

    private function user(): object
    {
        $user = $this->tokenStorage?->getToken()?->getUser();

        return is_object($user) ? $user : new stdClass();
    }

    private function token(string $id): ?string
    {
        return $this->csrf?->getToken($id)->getValue();
    }
}
