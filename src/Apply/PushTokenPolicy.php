<?php

declare(strict_types=1);

namespace Nowo\UptimeMonitorBundle\Apply;

use SensitiveParameter;

use function count;
use function count_chars;
use function hash;
use function hex2bin;
use function max;
use function ord;
use function preg_match;
use function strlen;
use function substr;

/**
 * Format and minimum-entropy rules of an external push token (since 1.18): lowercase hex of 64–128 characters
 * (32–64 random bytes, e.g. `openssl rand -hex 32`). A single sample cannot prove entropy, so the policy refuses the
 * shapes a person or a placeholder produces and a CSPRNG output practically never does. Results are codes only.
 */
final class PushTokenPolicy
{
    public const MIN_LENGTH = 64;
    public const MAX_LENGTH = 128;

    /**
     * The code of the first rule the token breaks (`token_invalid_format`, `token_too_short`, `token_low_entropy`), or
     * null when it is acceptable.
     */
    public static function violation(#[SensitiveParameter] string $token): ?string
    {
        $length = strlen($token);
        if (preg_match('/^[0-9a-f]*$/D', $token) !== 1 || $length > self::MAX_LENGTH || $length % 2 !== 0) {
            return 'token_invalid_format';
        }
        if ($length < self::MIN_LENGTH) {
            return 'token_too_short';
        }

        return self::lowEntropy((string) hex2bin($token)) || $token === hash('sha256', '') ? 'token_low_entropy' : null;
    }

    /**
     * Fewer than 16 distinct bytes or one byte more than 6 times in the first 32, a repeated block of up to 16 bytes,
     * or a run of more than 8 bytes that steps by ±1.
     */
    private static function lowEntropy(#[SensitiveParameter] string $bytes): bool
    {
        $counts = count_chars(substr($bytes, 0, 32), 1);
        if (count($counts) < 16 || max($counts) > 6) {
            return true;
        }
        for ($period = 1; $period <= 16; ++$period) {
            if (substr($bytes, $period) === substr($bytes, 0, -$period)) {
                return true;
            }
        }
        $run = 1;
        for ($i = 1, $n = strlen($bytes); $i < $n; ++$i) {
            $step = (ord($bytes[$i]) - ord($bytes[$i - 1]) + 256) % 256;
            $run  = $step === 1 || $step === 255 ? $run + 1 : 1;
            if ($run > 8) {
                return true;
            }
        }

        return false;
    }
}
