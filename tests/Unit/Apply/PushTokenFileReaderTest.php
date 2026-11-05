<?php

declare(strict_types=1);

namespace Nowo\UptimeMonitorBundle\Tests\Unit\Apply;

use LogicException;
use Nowo\UptimeMonitorBundle\Apply\ApplyError;
use Nowo\UptimeMonitorBundle\Apply\ApplyRefusedException;
use Nowo\UptimeMonitorBundle\Apply\ExternalPushTokens;
use Nowo\UptimeMonitorBundle\Apply\MonitorFileParser;
use Nowo\UptimeMonitorBundle\Apply\MonitorFileSpec;
use Nowo\UptimeMonitorBundle\Apply\NativeTokenSourceSystem;
use Nowo\UptimeMonitorBundle\Apply\PushTokenFileReader;
use Nowo\UptimeMonitorBundle\Apply\TokenSourceSystem;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

use function array_map;
use function bin2hex;
use function chmod;
use function file_put_contents;
use function fopen;
use function fwrite;
use function hash;
use function is_dir;
use function is_file;
use function is_link;
use function is_resource;
use function json_encode;
use function mkdir;
use function print_r;
use function random_bytes;
use function rewind;
use function rmdir;
use function serialize;
use function str_repeat;
use function symlink;
use function sys_get_temp_dir;
use function uniqid;
use function unlink;

use const JSON_THROW_ON_ERROR;

#[CoversClass(PushTokenFileReader::class)]
#[CoversClass(NativeTokenSourceSystem::class)]
#[CoversClass(ExternalPushTokens::class)]
final class PushTokenFileReaderTest extends TestCase
{
    private string $dir;
    private MonitorFileSpec $spec;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/uptime-tokens-' . uniqid('', true);
        mkdir($this->dir, 0o700);
        $this->spec = (new MonitorFileParser())->parse(MonitorFileParserTest::file([
            MonitorFileParserTest::entry(),
            MonitorFileParserTest::entry(['key' => 'second', 'url_key' => 'fedcba9876543210fedcba9876543210']),
        ]));
    }

    protected function tearDown(): void
    {
        foreach (['tokens.json', 'link.json', 'sub'] as $name) {
            $path = $this->dir . '/' . $name;
            if (is_link($path) || is_file($path)) {
                unlink($path);
            } elseif (is_dir($path)) {
                rmdir($path);
            }
        }
        rmdir($this->dir);
    }

    /**
     * @param array<string, mixed> $map
     */
    private function tokenFile(array $map, int $mode = 0o600): string
    {
        $path = $this->dir . '/tokens.json';
        file_put_contents($path, json_encode($map, JSON_THROW_ON_ERROR));
        chmod($path, $mode);

        return $path;
    }

    /**
     * @return resource
     */
    private function stream(string $content): mixed
    {
        $stream = fopen('php://memory', 'r+');
        self::assertTrue(is_resource($stream));
        fwrite($stream, $content);
        rewind($stream);

        return $stream;
    }

    private function refusal(callable $read): string
    {
        try {
            $read();
        } catch (ApplyRefusedException $refused) {
            self::assertSame(4, $refused->getExitCode());
            self::assertNull($refused->getPrevious());

            return $refused->getErrors()[0]->path . ' ' . $refused->getErrors()[0]->code;
        }
        self::fail('The tokens were accepted.');
    }

    public function testA0600FileIsHashedAtOnce(): void
    {
        $token  = bin2hex(random_bytes(32));
        $reader = new PushTokenFileReader();

        foreach ([0o600, 0o400] as $mode) {
            $tokens = $reader->read($this->tokenFile(['little-vps-1.resources' => $token, 'second' => null], $mode), $this->spec, $this->stream(''));
            self::assertSame(hash('sha256', $token), $tokens->hashFor('little-vps-1.resources'));
            self::assertTrue($tokens->covers('second'));
            self::assertNull($tokens->hashFor('second'));
            self::assertFalse($tokens->covers('other'));
            self::assertSame(['little-vps-1.resources', 'second'], $tokens->keys());
        }
    }

    public function testStdinIsReadToTheEndWithoutATerminal(): void
    {
        $token  = bin2hex(random_bytes(32));
        $tokens = (new PushTokenFileReader())->read('-', $this->spec, $this->stream(json_encode(['second' => $token], JSON_THROW_ON_ERROR)));

        self::assertSame(hash('sha256', $token), $tokens->hashFor('second'));
    }

    public function testWithoutAValidFileTheTokensAreStillCheckedButNotAgainstEntryKeys(): void
    {
        $token  = bin2hex(random_bytes(32));
        $tokens = (new PushTokenFileReader())->read('-', null, $this->stream(json_encode(['any-key' => $token], JSON_THROW_ON_ERROR)));
        self::assertSame(hash('sha256', $token), $tokens->hashFor('any-key'));

        try {
            (new PushTokenFileReader())->read('-', null, $this->stream(json_encode(['any-key' => str_repeat('ab', 16)], JSON_THROW_ON_ERROR)));
            self::fail('A short token is refused without the file too.');
        } catch (ApplyRefusedException $refused) {
            self::assertSame([['token-file:/any-key', 'token_too_short']], array_map(static fn (ApplyError $error): array => [$error->path, $error->code], $refused->getErrors()));
        }
    }

    public function testTheFileMustBeAPrivateRegularFile(): void
    {
        $reader = new PushTokenFileReader();
        $stdin  = $this->stream('');
        foreach ([0o640, 0o604, 0o660, 0o644, 0o610] as $mode) {
            $path = $this->tokenFile(['second' => null], $mode);
            self::assertSame(' token_file_permissions', $this->refusal(fn (): ExternalPushTokens => $reader->read($path, $this->spec, $stdin)), (string) $mode);
        }
        $file = $this->tokenFile(['second' => null]);
        symlink($file, $this->dir . '/link.json');
        self::assertSame(' token_file_symlink', $this->refusal(fn (): ExternalPushTokens => $reader->read($this->dir . '/link.json', $this->spec, $stdin)));
        mkdir($this->dir . '/sub');
        self::assertSame(' token_file_not_regular', $this->refusal(fn (): ExternalPushTokens => $reader->read($this->dir . '/sub', $this->spec, $stdin)));
        self::assertSame(' token_file_unreadable', $this->refusal(fn (): ExternalPushTokens => $reader->read($this->dir . '/missing.json', $this->spec, $stdin)));
    }

    public function testRacesForeignOwnersAndTerminalsAreRefused(): void
    {
        $path = $this->tokenFile(['second' => null]);
        $real = new NativeTokenSourceSystem();
        $stat = $real->lstat($path);
        self::assertIsArray($stat);

        $swapped = $this->system($stat, ['ino' => $stat['ino'] + 1] + $stat);
        self::assertSame(' token_file_changed', $this->refusal(fn (): ExternalPushTokens => (new PushTokenFileReader($swapped))->read($path, $this->spec, $this->stream(''))));

        $unopenable = $this->system($stat, $stat, open: false);
        self::assertSame(' token_file_changed', $this->refusal(fn (): ExternalPushTokens => (new PushTokenFileReader($unopenable))->read($path, $this->spec, $this->stream(''))));

        $foreign = $this->system($stat, ['uid' => 4242] + $stat, uid: 1000);
        self::assertSame(' token_file_owner', $this->refusal(fn (): ExternalPushTokens => (new PushTokenFileReader($foreign))->read($path, $this->spec, $this->stream(''))));

        $rootOwned = $this->system($stat, ['uid' => 0] + $stat, uid: 1000);
        self::assertTrue((new PushTokenFileReader($rootOwned))->read($path, $this->spec, $this->stream(''))->covers('second'));

        $noPosix = $this->system($stat, ['uid' => 4242] + $stat, uid: null);
        self::assertTrue((new PushTokenFileReader($noPosix))->read($path, $this->spec, $this->stream(''))->covers('second'));

        $terminal = $this->system($stat, $stat, tty: true);
        self::assertSame(' token_input_tty', $this->refusal(fn (): ExternalPushTokens => (new PushTokenFileReader($terminal))->read('-', $this->spec, $this->stream('{"second": null}'))));
    }

    public function testTheNativeSystemCalls(): void
    {
        $system = new NativeTokenSourceSystem();
        $path   = $this->tokenFile([]);
        $handle = $system->open($path);
        self::assertTrue(is_resource($handle));
        self::assertSame($system->lstat($path)['ino'] ?? null, $system->fstat($handle)['ino'] ?? false);
        self::assertFalse($system->isTty($handle));
        self::assertFalse($system->lstat($this->dir . '/missing'));
        self::assertFalse($system->open($this->dir . '/missing'));
        self::assertIsInt($system->effectiveUid());
    }

    public function testTheContentIsBoundedAndWellFormed(): void
    {
        $reader = new PushTokenFileReader();
        $read   = fn (string $content): string => $this->refusal(fn (): ExternalPushTokens => $reader->read('-', $this->spec, $this->stream($content)));

        self::assertSame(' token_input_empty', $read(''));
        self::assertSame(' token_input_empty', $read(" \n"));
        self::assertSame(' token_input_too_large', $read('{"second": "' . str_repeat('a', PushTokenFileReader::MAX_BYTES) . '"}'));
        self::assertSame(' token_file_invalid_json', $read('{"second": '));
        self::assertSame(' token_file_invalid_json', $read('["a"]'));
        $many = [];
        for ($i = 0; $i <= PushTokenFileReader::MAX_ENTRIES; ++$i) {
            $many['k' . $i] = null;
        }
        self::assertSame(' token_file_too_many', $read(json_encode($many, JSON_THROW_ON_ERROR)));
    }

    public function testEachTokenIsCheckedAndNamedByItsKeyOnly(): void
    {
        $reader = new PushTokenFileReader();
        $token  = bin2hex(random_bytes(32));
        $read   = function (array $map) use ($reader): array {
            try {
                $reader->read('-', $this->spec, $this->stream(json_encode($map, JSON_THROW_ON_ERROR)));
            } catch (ApplyRefusedException $refused) {
                self::assertStringNotContainsString('aaaa', $refused->getMessage());

                return array_map(static fn (ApplyError $error): string => $error->path . ' ' . $error->code, $refused->getErrors());
            }
            self::fail('accepted');
        };

        self::assertSame(['token-file:/typo token_for_unknown_key', 'token-file:/? token_for_unknown_key'], $read(['typo' => null, 'Bad Key' => null]));
        self::assertSame(['token-file:/second token_invalid_format'], $read(['second' => 42]));
        self::assertSame(['token-file:/second token_too_short'], $read(['second' => str_repeat('a', 32)]));
        self::assertSame(['token-file:/second token_low_entropy'], $read(['second' => str_repeat('a', 64)]));
        self::assertSame(['token-file:/second token_duplicate'], $read(['little-vps-1.resources' => $token, 'second' => $token]));
        $withKey = 'fedcba9876543210fedcba9876543210' . bin2hex(random_bytes(16));
        self::assertSame(['token-file:/second token_contains_url_key'], $read(['second' => $withKey]));
    }

    public function testTheTokensNeverShowInDumpsAndRefuseSerialization(): void
    {
        $token  = bin2hex(random_bytes(32));
        $tokens = (new PushTokenFileReader())->read('-', $this->spec, $this->stream(json_encode(['second' => $token], JSON_THROW_ON_ERROR)));

        self::assertSame(['keys' => ['second']], $tokens->__debugInfo());
        self::assertStringNotContainsString(hash('sha256', $token), print_r($tokens, true));
        self::assertSame('{}', json_encode($tokens, JSON_THROW_ON_ERROR));
        $this->expectException(LogicException::class);
        serialize($tokens);
    }

    /**
     * @param array<int|string, int> $lstat
     * @param array<int|string, int> $fstat
     */
    private function system(array $lstat, array $fstat, bool $open = true, ?int $uid = 0, bool $tty = false): TokenSourceSystem
    {
        return new class($lstat, $fstat, $open, $uid, $tty) implements TokenSourceSystem {
            /**
             * @param array<int|string, int> $lstat
             * @param array<int|string, int> $fstat
             */
            public function __construct(private readonly array $lstat, private readonly array $fstat, private readonly bool $open, private readonly ?int $uid, private readonly bool $tty)
            {
            }

            public function lstat(string $path): array
            {
                return $this->lstat;
            }

            public function open(string $path): mixed
            {
                return $this->open ? fopen($path, 'r') : false;
            }

            public function fstat(mixed $handle): array
            {
                return $this->fstat;
            }

            public function isTty(mixed $stream): bool
            {
                return $this->tty;
            }

            public function effectiveUid(): ?int
            {
                return $this->uid;
            }
        };
    }
}
