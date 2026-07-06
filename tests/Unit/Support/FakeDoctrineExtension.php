<?php

declare(strict_types=1);

namespace Nowo\UptimeMonitorBundle\Tests\Unit\Support;

use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Extension\ExtensionInterface;
use Symfony\Component\DependencyInjection\Extension\PrependExtensionInterface;

final class FakeDoctrineExtension implements ExtensionInterface, PrependExtensionInterface
{
    public function load(array $configs, ContainerBuilder $container): void
    {
    }

    public function prepend(ContainerBuilder $container): void
    {
    }

    public function getAlias(): string
    {
        return 'doctrine';
    }

    public function getNamespace(): string
    {
        return 'http://example.test/schema/doctrine';
    }

    public function getXsdValidationBasePath(): string|false
    {
        return false;
    }
}
