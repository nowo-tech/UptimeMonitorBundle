<?php

declare(strict_types=1);

namespace Nowo\UptimeMonitorBundle\Security;

use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;

/**
 * Default role-based access checker driven by nowo_uptime_monitor.security.* config.
 *
 * access_roles is the general gate; dashboard/manage/settings roles are area-specific. A user is allowed in an area
 * when they hold a role of every non-empty list among the two (access_roles and the area list). An empty list grants
 * nothing on its own: when both lists are empty nobody is allowed (fail closed, never "everyone").
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

    /**
     * Whether the user passes the access roles and holds one of the dashboard roles.
     */
    public function canAccessDashboard(object $user): bool
    {
        return $this->isAllowed($this->dashboardRoles);
    }

    /**
     * Whether the user passes the access roles and holds one of the manage roles.
     */
    public function canManageMonitors(object $user): bool
    {
        return $this->isAllowed($this->manageRoles);
    }

    /**
     * Whether the user passes the access roles and holds one of the settings roles.
     */
    public function canManageSettings(object $user): bool
    {
        return $this->isAllowed($this->settingsRoles);
    }

    /**
     * @param list<string> $areaRoles
     */
    private function isAllowed(array $areaRoles): bool
    {
        if ($this->accessRoles === [] && $areaRoles === []) {
            return false;
        }

        return ($this->accessRoles === [] || $this->hasAnyRole($this->accessRoles))
            && ($areaRoles === [] || $this->hasAnyRole($areaRoles));
    }

    /** @param list<string> $roles */
    private function hasAnyRole(array $roles): bool
    {
        foreach ($roles as $role) {
            if ($this->authorizationChecker->isGranted($role)) {
                return true;
            }
        }

        return false;
    }
}
