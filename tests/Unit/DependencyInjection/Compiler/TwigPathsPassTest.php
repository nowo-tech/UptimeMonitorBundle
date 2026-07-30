<?php

declare(strict_types=1);

namespace Nowo\UptimeMonitorBundle\Tests\Unit\DependencyInjection\Compiler;

use Nowo\UptimeMonitorBundle\DependencyInjection\Compiler\TwigPathsPass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;

final class TwigPathsPassTest extends TestCase
{
    public function testAddsBundleViewsPath(): void
    {
        $container = new ContainerBuilder();
        $container->setDefinition('twig.loader.native_filesystem', new Definition('stdClass'));

        (new TwigPathsPass())->process($container);

        $calls = $container->getDefinition('twig.loader.native_filesystem')->getMethodCalls();
        self::assertNotEmpty($calls);
        self::assertSame('addPath', $calls[array_key_last($calls)][0]);
        self::assertSame('NowoUptimeMonitorBundle', $calls[array_key_last($calls)][1][1]);
    }

    public function testNoOpWhenLoaderMissing(): void
    {
        $container = new ContainerBuilder();

        (new TwigPathsPass())->process($container);

        self::assertFalse($container->hasDefinition('twig.loader.native_filesystem'));
    }

    public function testPrependsApplicationOverrideWhenPresent(): void
    {
        $container = new ContainerBuilder();
        $container->setDefinition('twig.loader.native_filesystem', new Definition('stdClass'));
        $projectDir  = sys_get_temp_dir() . '/uptime-monitor-project-' . uniqid('', true);
        $overrideDir = $projectDir . '/templates/bundles/NowoUptimeMonitorBundle';
        mkdir($overrideDir, 0777, true);
        $container->setParameter('kernel.project_dir', $projectDir);

        try {
            (new TwigPathsPass())->process($container);

            $calls = $container->getDefinition('twig.loader.native_filesystem')->getMethodCalls();
            self::assertSame('prependPath', $calls[0][0]);
            self::assertSame('NowoUptimeMonitorBundle', $calls[0][1][1]);
            self::assertSame('addPath', $calls[array_key_last($calls)][0]);
            self::assertSame('NowoUptimeMonitorBundle', $calls[array_key_last($calls)][1][1]);
        } finally {
            @rmdir($overrideDir);
            @rmdir($projectDir . '/templates/bundles');
            @rmdir($projectDir . '/templates');
            @rmdir($projectDir);
        }
    }
}
