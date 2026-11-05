<?php

declare(strict_types=1);

namespace Nowo\UptimeMonitorBundle\Tests\Unit\Apply;

use Nowo\UptimeMonitorBundle\Apply\PushTokenPolicy;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

use function array_map;
use function bin2hex;
use function chr;
use function hash;
use function implode;
use function random_bytes;
use function range;
use function str_repeat;
use function strtoupper;
use function substr;

#[CoversClass(PushTokenPolicy::class)]
final class PushTokenPolicyTest extends TestCase
{
    public function testRandomTokensOf32To64BytesPass(): void
    {
        for ($i = 0; $i < 1000; ++$i) {
            self::assertNull(PushTokenPolicy::violation(bin2hex(random_bytes(32))));
        }
        self::assertNull(PushTokenPolicy::violation(bin2hex(random_bytes(64))));
    }

    /**
     * @return iterable<string, array{0: string, 1: string}>
     */
    public static function refused(): iterable
    {
        $random = bin2hex(random_bytes(32));
        $run    = '';
        for ($i = 0; $i < 32; ++$i) {
            $run .= chr($i < 10 ? 0x40 + $i : (($i * 37) + 11) % 256);
        }
        $repeated = '';
        for ($i = 0; $i < 32; ++$i) {
            $repeated .= chr(($i * 53) % 251);
        }

        yield 'empty' => ['', 'token_too_short'];
        yield 'uppercase hex' => [strtoupper($random), 'token_invalid_format'];
        yield 'upm_ token' => ['upm_' . $random, 'token_invalid_format'];
        yield 'odd length' => [$random . 'a', 'token_invalid_format'];
        yield 'too long' => [bin2hex(random_bytes(65)), 'token_invalid_format'];
        yield 'trailing newline' => [$random . "\n", 'token_invalid_format'];
        yield '63 characters' => [substr($random, 0, 63), 'token_invalid_format'];
        yield '62 characters' => [substr($random, 0, 62), 'token_too_short'];
        yield 'all zero' => [str_repeat('0', 64), 'token_low_entropy'];
        yield 'all f' => [str_repeat('f', 64), 'token_low_entropy'];
        yield 'a 4-byte block repeated' => [str_repeat('deadbeef', 8), 'token_low_entropy'];
        yield 'counting bytes' => [bin2hex(implode('', array_map(chr(...), range(0, 31)))), 'token_low_entropy'];
        yield 'a run of 10 bytes in a row' => [bin2hex($run), 'token_low_entropy'];
        yield 'a 16-byte block repeated' => [bin2hex(substr($repeated, 0, 16) . substr($repeated, 0, 16)), 'token_low_entropy'];
        yield 'one byte 7 times' => [bin2hex(str_repeat("\x07", 7) . substr($repeated, 7)), 'token_low_entropy'];
        yield 'sha-256 of nothing' => [hash('sha256', ''), 'token_low_entropy'];
    }

    #[DataProvider('refused')]
    public function testWeakOrMalformedTokensAreRefused(string $token, string $code): void
    {
        self::assertSame($code, PushTokenPolicy::violation($token));
    }

    public function testADistinctNonRepeatingSequencePasses(): void
    {
        $bytes = '';
        for ($i = 0; $i < 32; ++$i) {
            $bytes .= chr(($i * 53) % 251);
        }

        self::assertNull(PushTokenPolicy::violation(bin2hex($bytes)));
    }
}
