<?php

declare(strict_types=1);

namespace Nowo\UptimeMonitorBundle\Tests\Unit\Check;

use Nowo\UptimeMonitorBundle\Check\PingCheckRunner;
use Nowo\UptimeMonitorBundle\Entity\Monitor;
use Nowo\UptimeMonitorBundle\Entity\Tenant;
use Nowo\UptimeMonitorBundle\Enum\CheckStatus;
use Nowo\UptimeMonitorBundle\Enum\MonitorType;
use Nowo\UptimeMonitorBundle\Tests\Support\OfflineDns;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(PingCheckRunner::class)]
final class PingCheckRunnerTest extends TestCase
{
    /** @var list<string> */
    private array $commands = [];

    /**
     * @param array{0: int, 1: list<string>} $answer
     */
    private function runner(bool $block = true, array $answer = [0, []], string $os = 'Linux'): PingCheckRunner
    {
        $this->commands = [];

        return new PingCheckRunner(
            OfflineDns::guard(['public.example' => ['93.184.216.9'], 'private.example' => ['10.0.0.9']]),
            $block,
            function (string $command) use ($answer): array {
                $this->commands[] = $command;

                return $answer;
            },
            $os,
        );
    }

    /**
     * @param array<string, mixed> $config
     */
    private function monitor(array $config = [], string $target = 'public.example', MonitorType $type = MonitorType::Ping): Monitor
    {
        $monitor = new Monitor(new Tenant('main', 'Main'), 'Ping', $type, $target);
        $monitor->setConfig($config);

        return $monitor;
    }

    public function testSupportsOnlyPing(): void
    {
        self::assertTrue($this->runner()->supports($this->monitor()));
        self::assertFalse($this->runner()->supports($this->monitor([], 'x', MonitorType::Http)));
    }

    public function testUpParsesLatencyAndPingsTheResolvedAddress(): void
    {
        $runner = $this->runner(true, [0, ['64 bytes from 93.184.216.9: icmp_seq=1 ttl=56 time=12.5 ms']]);

        $result = $runner->run($this->monitor());

        self::assertSame(CheckStatus::Up, $result->status);
        self::assertSame(13, $result->latencyMs);
        self::assertSame(["ping -c 1 -W 5 -w 5 '93.184.216.9'"], $this->commands);
    }

    public function testUpWithoutTimingFallsBackToElapsedTime(): void
    {
        $result = $this->runner(false, [0, ['no timing here']])->run($this->monitor(['host' => '127.0.0.1', 'timeout' => 0.1]));

        self::assertSame(CheckStatus::Up, $result->status);
        self::assertGreaterThanOrEqual(0, $result->latencyMs);
        self::assertSame(["ping -c 1 -W 1 -w 1 '127.0.0.1'"], $this->commands);
    }

    public function testNonZeroExitIsDown(): void
    {
        $withOutput = $this->runner(true, [1, ['100% packet loss', 'done']])->run($this->monitor());
        self::assertSame(CheckStatus::Down, $withOutput->status);
        self::assertSame("100% packet loss\ndone", $withOutput->message);

        $silent = $this->runner(true, [2, []])->run($this->monitor());
        self::assertSame('Ping failed (exit code 2)', $silent->message);
    }

    /**
     * @return iterable<string, array{0: string, 1: string|null}>
     */
    public static function osProvider(): iterable
    {
        yield 'Linux' => ['Linux', "ping -c 1 -W 30 -w 30 '93.184.216.9'"];
        yield 'Darwin' => ['Darwin', "ping -c 1 -t 30 '93.184.216.9'"];
        yield 'BSD' => ['BSD', "ping -c 1 -W 30000 '93.184.216.9'"];
        yield 'Windows' => ['Windows', null];
    }

    #[DataProvider('osProvider')]
    public function testCommandDependsOnTheOperatingSystem(string $os, ?string $expected): void
    {
        $result = $this->runner(true, [0, ['time<1ms']], $os)->run($this->monitor(['timeout' => 90]));

        if ($expected === null) {
            self::assertSame(CheckStatus::Unknown, $result->status);
            self::assertSame('ICMP ping is not supported on this operating system', $result->message);
            self::assertSame([], $this->commands);

            return;
        }

        self::assertSame([$expected], $this->commands);
    }

    public function testInvalidOrOptionInjectingHostIsUnknown(): void
    {
        foreach (['-c 100 public.example', 'bad host', ''] as $host) {
            $result = $this->runner()->run($this->monitor(['host' => $host]));
            self::assertSame(CheckStatus::Unknown, $result->status);
            self::assertSame('Invalid ping host', $result->message);
        }

        self::assertSame([], $this->commands);
    }

    public function testPrivateAndUnresolvableHostsAreRejected(): void
    {
        $runner = $this->runner();

        foreach (['private.example', '127.0.0.1', 'localhost', '100.64.0.1'] as $host) {
            $result = $runner->run($this->monitor(['host' => $host]));
            self::assertSame(CheckStatus::Unknown, $result->status);
            self::assertStringContainsString('private or local', (string) $result->message);
        }

        $unresolvable = $runner->run($this->monitor(['host' => 'nowhere.invalid']));
        self::assertSame(CheckStatus::Down, $unresolvable->status);
        self::assertSame('Could not resolve host nowhere.invalid', $unresolvable->message);
        self::assertSame([], $this->commands);
    }

    public function testDefaultExecutorRunsTheCommand(): void
    {
        // Loopback only; the outcome depends on the ping binary/capabilities of the environment, not on the network.
        $runner = new PingCheckRunner(OfflineDns::guard(), false);

        $result = $runner->run($this->monitor(['host' => '127.0.0.1', 'timeout' => 1]));

        self::assertContains($result->status, [CheckStatus::Up, CheckStatus::Down, CheckStatus::Unknown]);
    }
}
