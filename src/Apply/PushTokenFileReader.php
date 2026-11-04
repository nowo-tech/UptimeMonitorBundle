<?php

declare(strict_types=1);

namespace Nowo\UptimeMonitorBundle\Apply;

use JsonException;
use Nowo\UptimeMonitorBundle\Push\PushCredentials;
use SensitiveParameter;
use stdClass;

use function count;
use function fclose;
use function function_exists;
use function get_object_vars;
use function is_resource;
use function is_string;
use function json_decode;
use function preg_match;
use function sodium_memzero;
use function str_contains;
use function stream_get_contents;
use function strlen;
use function trim;

use const JSON_THROW_ON_ERROR;

/**
 * The push token intake of `nowo:uptime:apply-monitors` (since 1.18). Tokens arrive only from a 0600 regular file
 * (`--token-file=<path>`) or from a non-interactive stdin (`--token-file=-`), as a JSON map `{"<entry key>": "<token>"}`
 * of at most {@see self::MAX_BYTES}. Each token is checked by {@see PushTokenPolicy}, reduced to its SHA-256 at once,
 * and the plaintext is wiped where ext-sodium allows. Refusals (exit 4) name the entry key, never the value.
 */
final readonly class PushTokenFileReader
{
    public const STDIN       = '-';
    public const MAX_BYTES   = 65_536;
    public const MAX_ENTRIES = 1_000;
    private const S_IFMT     = 0o170000;
    private const S_IFREG    = 0o100000;
    private const S_IFLNK    = 0o120000;

    /**
     * @param TokenSourceSystem $system Filesystem and process calls
     */
    public function __construct(
        private TokenSourceSystem $system = new NativeTokenSourceSystem(),
    ) {
    }

    /**
     * Reads, checks and hashes the tokens of `$source` (a path, or `-` for `$stdin`).
     *
     * @param MonitorFileSpec $spec The whole monitors file: every token must name one of its entries
     * @param resource $stdin The stream read for `-`
     *
     * @throws ApplyRefusedException exit 4
     */
    public function read(string $source, MonitorFileSpec $spec, mixed $stdin): ExternalPushTokens
    {
        $content = $source === self::STDIN ? $this->readStdin($stdin) : $this->readFile($source);
        if (trim($content) === '') {
            throw $this->refuse('', 'token_input_empty');
        }
        if (strlen($content) > self::MAX_BYTES) {
            throw $this->refuse('', 'token_input_too_large');
        }

        try {
            $map = json_decode($content, false, 4, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            $map = null;
        }
        $this->wipe($content);
        if (!$map instanceof stdClass) {
            throw $this->refuse('', 'token_file_invalid_json');
        }
        $tokens = get_object_vars($map);
        if (count($tokens) > self::MAX_ENTRIES) {
            throw $this->refuse('', 'token_file_too_many');
        }

        return $this->hashAll($tokens, $spec);
    }

    /**
     * @param array<array-key, mixed> $tokens
     *
     * @throws ApplyRefusedException exit 4
     */
    private function hashAll(#[SensitiveParameter] array $tokens, MonitorFileSpec $spec): ExternalPushTokens
    {
        $urlKeys = [];
        foreach ($spec->entries() as $entry) {
            $urlKeys[$entry->key] = $entry->urlKey;
        }

        $errors = [];
        $hashes = [];
        $seen   = [];
        foreach ($tokens as $key => $token) {
            $key  = (string) $key;
            $path = 'token-file:/' . (preg_match(MonitorFileParser::KEY_PATTERN, $key) === 1 ? $key : '?');
            if (!isset($urlKeys[$key])) {
                $errors[] = new ApplyError($path, 'token_for_unknown_key', ApplyError::EXIT_TOKEN);
                continue;
            }
            if ($token === null) {
                $hashes[$key] = null;
                continue;
            }
            $plain = is_string($token) ? $token : '';
            $code  = is_string($token) ? PushTokenPolicy::violation($plain) : 'token_invalid_format';
            if ($code === null && str_contains($plain, $urlKeys[$key])) {
                $code = 'token_contains_url_key';
            }
            $hash = PushCredentials::hashToken($plain);
            if ($code === null && isset($seen[$hash])) {
                $code = 'token_duplicate';
            }
            $this->wipe($plain);
            if ($code !== null) {
                $errors[] = new ApplyError($path, $code, ApplyError::EXIT_TOKEN);
                continue;
            }
            $seen[$hash]  = true;
            $hashes[$key] = $hash;
        }
        if ($errors !== []) {
            throw new ApplyRefusedException($errors);
        }

        return new ExternalPushTokens($hashes);
    }

    /**
     * @param resource $stdin
     *
     * @throws ApplyRefusedException exit 4 on a terminal
     */
    private function readStdin(mixed $stdin): string
    {
        // A terminal would wait for typing (and echo the token): refused before any read.
        if ($this->system->isTty($stdin)) {
            throw $this->refuse('', 'token_input_tty');
        }

        // Read to EOF, never more than one byte past the cap.
        return (string) stream_get_contents($stdin, self::MAX_BYTES + 1);
    }

    /**
     * @throws ApplyRefusedException exit 4
     */
    private function readFile(string $path): string
    {
        $link = $this->system->lstat($path);
        if ($link === false) {
            throw $this->refuse('', 'token_file_unreadable');
        }
        $kind = $link['mode'] & self::S_IFMT;
        if ($kind !== self::S_IFREG) {
            throw $this->refuse('', $kind === self::S_IFLNK ? 'token_file_symlink' : 'token_file_not_regular');
        }

        $handle = $this->system->open($path);
        $stat   = is_resource($handle) ? $this->system->fstat($handle) : false;
        if ($stat === false || $stat['ino'] !== $link['ino'] || $stat['dev'] !== $link['dev']) {
            $this->close($handle);

            throw $this->refuse('', 'token_file_changed');
        }
        if (($stat['mode'] & 0o077) !== 0) {
            $this->close($handle);

            throw $this->refuse('', 'token_file_permissions');
        }
        $uid = $this->system->effectiveUid();
        if ($uid !== null && $stat['uid'] !== $uid && $stat['uid'] !== 0) {
            $this->close($handle);

            throw $this->refuse('', 'token_file_owner');
        }

        $content = (string) stream_get_contents($handle, self::MAX_BYTES + 1);
        $this->close($handle);

        return $content;
    }

    /**
     * @param false|resource $handle
     */
    private function close(mixed $handle): void
    {
        if (is_resource($handle)) {
            fclose($handle);
        }
    }

    /**
     * Overwrites a string that held token material (best effort: PHP may keep copies).
     *
     * @param-out string|null $secret
     */
    private function wipe(#[SensitiveParameter] string &$secret): void
    {
        if (function_exists('sodium_memzero')) {
            sodium_memzero($secret);
        }
    }

    private function refuse(string $path, string $code): ApplyRefusedException
    {
        return new ApplyRefusedException([new ApplyError($path, $code, ApplyError::EXIT_TOKEN)]);
    }
}
