<?php

declare(strict_types=1);

namespace Nowo\UptimeMonitorBundle\Tests\Unit\DependencyInjection;

use Nowo\UptimeMonitorBundle\DependencyInjection\Configuration;
use Nowo\UptimeMonitorBundle\Ui\MonitorShowLayout;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\RequiresPhp;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;
use Symfony\Component\Config\Definition\Dumper\XmlReferenceDumper;
use Symfony\Component\Config\Definition\Dumper\YamlReferenceDumper;
use Symfony\Component\Config\Definition\Processor;

use function array_chunk;
use function array_merge;
use function dirname;
use function fclose;
use function is_resource;
use function json_decode;
use function proc_close;
use function proc_open;
use function sort;
use function stream_get_contents;
use function stream_set_timeout;
use function var_export;

use const JSON_THROW_ON_ERROR;
use const PHP_BINARY;

/**
 * PHP 8.3+ stops the compiler at zend.max_allowed_stack_size ("Maximum call stack size ... reached during
 * compilation"). A FrankenPHP thread on Alpine (musl) has a 512 KiB stack, so a file whose compilation needs more
 * cannot be loaded there: up to 1.34.0 the one fluent chain of Configuration::getConfigTreeBuilder() needed 576 KiB
 * (PHP 8.5) to 704 KiB (PHP 8.3 / 8.4), and every bundle page failed because the UI reads Configuration constants.
 *
 * The CLI main thread of the test run has 8 MiB, so these tests run PHP in a child process with the stack capped at
 * the FrankenPHP thread size.
 */
#[CoversClass(Configuration::class)]
#[RequiresPhp('>= 8.3')]
final class ConfigurationThreadStackTest extends TestCase
{
    /** The stack of a FrankenPHP thread on Alpine (dunglas/frankenphp:*-alpine). */
    private const THREAD_STACK = '512K';

    private const ROOT = __DIR__ . '/../../..';

    public function testTheConfigurationLoadsBuildsAndProcessesOnAFrankenPhpThreadStack(): void
    {
        [$code, $stdout, $stderr] = $this->php([dirname(__DIR__, 2) . '/Fixtures/configuration-thread-stack.php']);

        self::assertSame(0, $code, $stdout . $stderr);
        /** @var array{defaults: string, layout: string, yaml: string, xml: string} $child */
        $child         = json_decode($stdout, true, 512, JSON_THROW_ON_ERROR);
        $configuration = new Configuration();
        $defaults      = (new Processor())->processConfiguration($configuration, [[]]);
        self::assertSame(var_export($defaults, true), $child['defaults']);
        self::assertSame(var_export(MonitorShowLayout::resolve($defaults['web_ui']['monitor_show']), true), $child['layout']);
        self::assertSame((new YamlReferenceDumper())->dump($configuration), $child['yaml']);
        self::assertSame((new XmlReferenceDumper())->dump($configuration), $child['xml']);
    }

    /**
     * Every class of the bundle may be loaded inside a request, so every source file must compile on that stack.
     */
    public function testEverySourceFileCompilesOnAFrankenPhpThreadStack(): void
    {
        $files = [];
        /** @var SplFileInfo $file */
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator(self::ROOT . '/src', RecursiveDirectoryIterator::SKIP_DOTS)) as $file) {
            if ($file->getExtension() === 'php') {
                $files[] = $file->getPathname();
            }
        }
        sort($files);
        self::assertNotEmpty($files);

        foreach (array_chunk($files, 50) as $chunk) {
            // php -l takes several files since PHP 8.3 and compiles each one.
            [$code, $stdout, $stderr] = $this->php(array_merge(['-l'], $chunk));

            self::assertSame(0, $code, $stdout . $stderr);
        }
    }

    /**
     * @param list<string> $args
     *
     * @return array{0: int, 1: string, 2: string}
     */
    private function php(array $args): array
    {
        // A test helper, not worker code: the pipes get a read timeout below.
        $process = proc_open( // @phpstan-ignore frankenphp.classic.noUnlimitedIoTimeout
            array_merge([PHP_BINARY, '-d', 'zend.max_allowed_stack_size=' . self::THREAD_STACK, '-d', 'xdebug.mode=off'], $args),
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            self::ROOT,
        );
        self::assertTrue(is_resource($process));
        stream_set_timeout($pipes[1], 60);
        stream_set_timeout($pipes[2], 60);
        $stdout = (string) stream_get_contents($pipes[1]);
        $stderr = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        return [proc_close($process), $stdout, $stderr];
    }
}
