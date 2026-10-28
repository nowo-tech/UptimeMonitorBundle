<?php

declare(strict_types=1);

namespace Nowo\UptimeMonitorBundle\Tests\Unit\Push;

use Nowo\UptimeMonitorBundle\Push\PushPayload;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

use function array_fill;
use function json_encode;

use const JSON_THROW_ON_ERROR;

#[CoversClass(PushPayload::class)]
final class PushPayloadTest extends TestCase
{
    public function testAFullSampleIsNormalised(): void
    {
        [$sample, $errors] = PushPayload::parse('{"cpu_pct":12.345,"mem_pct":61,"swap_pct":0,"load1":0.4,"load5":0.3,"load15":0.2,"cpu_count":4,"uptime_s":86400,"disks":[{"mount":"/","used_pct":71.25,"inodes_pct":9},{"mount":"/var"}]}');

        self::assertSame([], $errors);
        self::assertSame([
            'cpu_pct'   => 12.35,
            'mem_pct'   => 61.0,
            'swap_pct'  => 0.0,
            'load1'     => 0.4,
            'load5'     => 0.3,
            'load15'    => 0.2,
            'cpu_count' => 4,
            'uptime_s'  => 86400,
            'disks'     => [['mount' => '/', 'used_pct' => 71.25, 'inodes_pct' => 9.0], ['mount' => '/var']],
        ], $sample);
    }

    public function testAnEmptyBodyOrObjectIsAHeartbeat(): void
    {
        self::assertSame([[], []], PushPayload::parse(''));
        self::assertSame([[], []], PushPayload::parse('{}'));
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function invalidBodies(): iterable
    {
        yield 'not json' => ['{', 'body: invalid JSON'];
        yield 'a list' => ['[1,2]', 'body: must be a JSON object'];
        yield 'a scalar' => ['42', 'body: must be a JSON object'];
        yield 'too deep' => ['{"a":{"b":{"c":{"d":1}}}}', 'body: invalid JSON'];
        yield 'unknown key' => ['{"hostname":"x"}', 'hostname: unknown key'];
        yield 'odd unknown key' => ['{"<script>":1}', '(key): unknown key'];
        yield 'out of range' => ['{"cpu_pct":101}', 'cpu_pct: number between 0 and 100 expected'];
        yield 'negative' => ['{"mem_pct":-1}', 'mem_pct: number between 0 and 100 expected'];
        yield 'a string number' => ['{"load1":"1"}', 'load1: number between 0 and 10000 expected'];
        yield 'zero cores' => ['{"cpu_count":0}', 'cpu_count: number between 1 and 4096 expected'];
        yield 'disks not a list' => ['{"disks":{"a":1}}', 'disks: list of at most 32 disks expected'];
        yield 'disk without mount' => ['{"disks":[{"used_pct":1}]}', 'disks[0].mount: printable string of 1 to 255 characters expected'];
        yield 'control characters' => ['{"disks":[{"mount":"/\u0007"}]}', 'disks[0].mount: printable string of 1 to 255 characters expected'];
        yield 'disk not an object' => ['{"disks":["/"]}', 'disks[0].mount: printable string of 1 to 255 characters expected'];
        yield 'disk unknown key' => ['{"disks":[{"mount":"/","device":"sda"}]}', 'disks[0]: unknown key'];
        yield 'disk out of range' => ['{"disks":[{"mount":"/","used_pct":150}]}', 'disks[0].used_pct: number between 0 and 100 expected'];
    }

    #[DataProvider('invalidBodies')]
    public function testInvalidBodiesAreRefusedWithoutEchoingThem(string $body, string $error): void
    {
        [$sample, $errors] = PushPayload::parse($body);

        self::assertNull($sample);
        self::assertContains($error, $errors);
    }

    public function testAtMost32Disks(): void
    {
        $body = json_encode(['disks' => array_fill(0, 33, ['mount' => '/'])], JSON_THROW_ON_ERROR);

        self::assertSame([null, ['disks: list of at most 32 disks expected']], PushPayload::parse($body));
    }
}
