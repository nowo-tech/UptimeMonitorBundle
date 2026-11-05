<?php

declare(strict_types=1);

namespace Nowo\UptimeMonitorBundle\Tests\Integration\Apply;

use Nowo\UptimeMonitorBundle\Command\ApplyMonitorsCommand;
use Nowo\UptimeMonitorBundle\Tests\Unit\Apply\MonitorFileParserTest;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

use function bin2hex;
use function fclose;
use function fflush;
use function file_get_contents;
use function file_put_contents;
use function fwrite;
use function is_readable;
use function is_resource;
use function json_decode;
use function json_encode;
use function proc_close;
use function proc_open;
use function random_bytes;
use function str_repeat;
use function str_replace;
use function stream_get_contents;
use function stream_set_timeout;
use function strlen;
use function substr;
use function sys_get_temp_dir;
use function tempnam;
use function unlink;
use function usleep;

use const JSON_THROW_ON_ERROR;
use const PHP_BINARY;

/**
 * {@see ApplyMonitorsCommand} as a real process, the way DEVOPS runs it (`docker compose exec -T … --token-file=-`
 * with the token map on stdin): a writer that delivers the map in several chunks is read to EOF without a terminal,
 * empty or oversized input is refused with exit 4, and the token never appears in the process command line.
 */
#[Group('process')]
#[CoversNothing]
final class ApplyMonitorsProcessTest extends TestCase
{
    private const FIXTURE = __DIR__ . '/../../Fixtures/apply-monitors-console.php';

    /** @var list<string> */
    private array $files = [];

    protected function tearDown(): void
    {
        foreach ($this->files as $file) {
            @unlink($file);
        }
    }

    private function tempFile(string $content = ''): string
    {
        $path = (string) tempnam(sys_get_temp_dir(), 'uptime-apply-process');
        file_put_contents($path, $content);
        $this->files[] = $path;

        return $path;
    }

    /**
     * Runs the fixture console with `$chunks` written to its stdin one by one (with a pause between them).
     *
     * @param list<string> $chunks
     * @param array<string, string> $env
     *
     * @return array{0: int, 1: array<string, mixed>, 2: string}
     */
    private function runConsole(array $chunks, array $env = []): array
    {
        $file = $this->tempFile(MonitorFileParserTest::file([MonitorFileParserTest::entry()]));
        // A test helper, not worker code: the pipes get a read timeout below and stdin is always closed.
        $process = proc_open( // @phpstan-ignore frankenphp.classic.noUnlimitedIoTimeout
            [PHP_BINARY, self::FIXTURE, '--file=' . $file, '--token-file=-'],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            null,
            $env,
        );
        self::assertTrue(is_resource($process));
        foreach ($chunks as $i => $chunk) {
            if ($i > 0) {
                usleep(300_000);
            }
            fwrite($pipes[0], $chunk);
            fflush($pipes[0]);
        }
        fclose($pipes[0]);
        stream_set_timeout($pipes[1], 30);
        $stdout = (string) stream_get_contents($pipes[1]);
        $stderr = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $code = proc_close($process);
        /** @var array<string, mixed> $json */
        $json = json_decode($stdout, true, 16, JSON_THROW_ON_ERROR);

        return [$code, $json, $stderr];
    }

    public function testAPipedTokenMapIsReadToTheEndInChunksAndNeverReachesTheCommandLine(): void
    {
        $marker  = bin2hex(random_bytes(32));
        $map     = json_encode(['little-vps-1.resources' => $marker], JSON_THROW_ON_ERROR);
        $cmdline = $this->tempFile();
        $half    = (int) (strlen($map) / 2);

        [$code, $json, $stderr] = $this->runConsole([substr($map, 0, $half), substr($map, $half)], ['UPTIME_CMDLINE_OUT' => $cmdline]);

        self::assertSame(0, $code, $stderr);
        self::assertSame('set', $json['created'][0]['token'] ?? null);
        self::assertStringContainsString('Applied: 1 created', $stderr);
        self::assertStringNotContainsString($marker, $stderr);
        if (!is_readable('/proc/self/cmdline')) {
            self::markTestIncomplete('No /proc on this platform: the command line was not inspected.');
        }
        $argv = str_replace("\0", ' ', (string) file_get_contents($cmdline));
        self::assertStringContainsString('--token-file=-', $argv, 'the listener saw the running command');
        self::assertStringNotContainsString($marker, $argv);
    }

    public function testEmptyOrOversizedStdinIsRefusedWithExit4(): void
    {
        [$code, $json] = $this->runConsole([]);
        self::assertSame([4, [['path' => '', 'code' => 'token_input_empty']]], [$code, $json['errors']]);

        [$code, $json] = $this->runConsole([" \n\t "]);
        self::assertSame([4, 'token_input_empty'], [$code, $json['errors'][0]['code']]);

        [$code, $json] = $this->runConsole([str_repeat('a', 40_000), str_repeat('b', 25_537)]);
        self::assertSame([4, 'token_input_too_large'], [$code, $json['errors'][0]['code']]);
    }
}
