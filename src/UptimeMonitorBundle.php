<?php

declare(strict_types=1);

namespace Nowo\UptimeMonitorBundle;

use Nowo\UptimeMonitorBundle\DependencyInjection\Compiler\CsrfProtectionPass;
use Nowo\UptimeMonitorBundle\DependencyInjection\Compiler\EntityManagerOptionPass;
use Nowo\UptimeMonitorBundle\DependencyInjection\Compiler\TwigPathsPass;
use Nowo\UptimeMonitorBundle\DependencyInjection\UptimeMonitorExtension;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Extension\ExtensionInterface;
use Symfony\Component\HttpKernel\Bundle\Bundle;

/**
 * Synthetic uptime monitoring for Symfony: scheduled checks, aggregates, and multi-tenant dashboard.
 */
final class UptimeMonitorBundle extends Bundle
{
    public const TRANSLATION_DOMAIN = 'NowoUptimeMonitorBundle';

    /**
     * Registers the bundle compiler passes (Twig template paths, fail-closed CSRF requirement).
     */
    public function build(ContainerBuilder $container): void
    {
        $container->addCompilerPass(new TwigPathsPass());
        $container->addCompilerPass(new CsrfProtectionPass());
        // 1.26.0 (audit LD-UM-M1): the named manager must exist and map the bundle entities.
        $container->addCompilerPass(new EntityManagerOptionPass());
    }

    /**
     * Returns the bundle extension (alias `nowo_uptime_monitor`).
     */
    public function getContainerExtension(): ExtensionInterface
    {
        if (!$this->extension instanceof ExtensionInterface) {
            // @igor-ignore - Boot-time Symfony Bundle extension cache (not request state).
            $this->extension = new UptimeMonitorExtension();
        }

        return $this->extension;
    }
}
