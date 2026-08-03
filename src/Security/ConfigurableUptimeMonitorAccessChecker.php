<?php

declare(strict_types=1);

namespace Nowo\UptimeMonitorBundle\Security;

use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;

/**
 * Default role-based access checker driven by nowo_uptime_monitor.security.* config.
 *
 * access_roles is the general gate; dashboard/manage/settings roles are area-specific.
 * Empty role lists skip that check (true).
 */
final readonly class ConfigurableUptimeMonitorAccessChecker implements UptimeMonitorAccessCheckerInterface
{
    /**
     * @param list<string> $accessRoles
     * @param list<string> $dashboardRoles
     * @param list<string> $manageRoles
     * @param list<string> $settingsRoles
     */
    public function __construct(
        private AuthorizationCheckerInterface $authorizationChecker,
        private array $accessRoles,
        private array $dashboardRoles,
        private array $manageRoles,
        private array $settingsRoles,
    ) {
    }

    public function canAccessDashboard(object $user): bool
    {
        return $this->passesAccessGate() && $this->hasAnyRole($this->dashboardRoles);
    }

    public function canManageMonitors(object $user): bool
    {
        return $this->passesAccessGate() && $this->hasAnyRole($this->manageRoles);
    }

    public function canManageSettings(object $user): bool
    {
        return $this->passesAccessGate() && $this->hasAnyRole($this->settingsRoles);
    }

    private function passesAccessGate(): bool
    {
        return $this->hasAnyRole($this->accessRoles);
    }

    /** @param list<string> $roles */
    private function hasAnyRole(array $roles): bool
    {
        if ($roles === []) {
            return true;
        }

        foreach ($roles as $role) {
            if ($this->authorizationChecker->isGranted($role)) {
                return true;
            }
        }

        return false;
    }
}
