<?php

declare(strict_types=1);

namespace Nowo\UptimeMonitorBundle;

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

    public function build(ContainerBuilder $container): void
    {
        $container->addCompilerPass(new TwigPathsPass());
    }

    public function getContainerExtension(): ExtensionInterface
    {
        if (!$this->extension instanceof ExtensionInterface) {
            $this->extension = new UptimeMonitorExtension();
        }

        return $this->extension;
    }
}
