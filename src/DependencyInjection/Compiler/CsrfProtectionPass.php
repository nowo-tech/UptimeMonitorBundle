<?php

declare(strict_types=1);

namespace Nowo\UptimeMonitorBundle\DependencyInjection\Compiler;

use LogicException;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;

use function is_array;

/**
 * Fails closed when the admin UI is enabled without form CSRF protection (REQ-SEC-005, SEC-004 L1).
 *
 * Every mutation of the admin UI (monitor, tenant and settings forms, backup import, purge / clear-stats / tag delete)
 * relies on Symfony's form CSRF extension. A host that disables `framework.form.csrf_protection` (or has no CSRF
 * token manager) would silently turn those checks off, so the container refuses to compile instead.
 */
final class CsrfProtectionPass implements CompilerPassInterface
{
    /**
     * Throws when the admin UI (`web_ui.enabled`) is on and form CSRF protection or the CSRF token manager is unavailable.
     */
    public function process(ContainerBuilder $container): void
    {
        if (!$container->hasParameter('nowo_uptime_monitor.dashboard')) {
            return;
        }

        $dashboard = $container->getParameter('nowo_uptime_monitor.dashboard');
        if (!is_array($dashboard) || !($dashboard['enabled'] ?? true)) {
            return;
        }

        $formCsrfDisabled = $container->hasParameter('form.type_extension.csrf.enabled')
            && $container->getParameter('form.type_extension.csrf.enabled') === false;

        if (
            !$container->has('security.csrf.token_manager')
            || !$container->hasDefinition('form.type_extension.csrf')
            || $formCsrfDisabled
        ) {
            throw new LogicException('NowoUptimeMonitorBundle: the admin UI (web_ui.enabled: true) requires CSRF protection. Enable framework.csrf_protection and framework.form.csrf_protection (composer require symfony/security-csrf), or set nowo_uptime_monitor.web_ui.enabled: false.');
        }
    }
}
