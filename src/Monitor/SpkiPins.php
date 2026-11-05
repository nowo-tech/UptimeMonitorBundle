<?php

declare(strict_types=1);

namespace Nowo\UptimeMonitorBundle\Monitor;

use OpenSSLCertificate;

use function array_is_list;
use function array_key_exists;
use function array_keys;
use function array_map;
use function base64_decode;
use function base64_encode;
use function count;
use function hash;
use function implode;
use function is_array;
use function is_string;
use function json_decode;
use function openssl_pkey_get_details;
use function openssl_pkey_get_public;
use function preg_match;
use function preg_replace;
use function preg_split;
use function str_starts_with;
use function strlen;
use function substr;
use function trim;

/**
 * SPKI pins of an SSL monitor (`expected_spki_pins`, since 1.20): `sha256/<base64>` hashes of a certificate's
 * SubjectPublicKeyInfo, the format of HPKP and of a `pins.json` list. The pins are public data, not secrets.
 */
final class SpkiPins
{
    /** Most pins an SSL monitor keeps. */
    public const MAX_PINS = 20;

    /** Longest form text (bytes) that is parsed; the form refuses longer input before parsing it. */
    public const MAX_TEXT_LENGTH = 4096;

    /** A SHA-256 digest (32 bytes) in standard base64 with its padding, prefixed `sha256/`. */
    public const PATTERN = '#^sha256/[A-Za-z0-9+/]{43}=$#D';

    /**
     * Whether the value is one well-formed `sha256/<base64>` pin: the pattern, and canonical base64 of exactly
     * 32 bytes (a non-canonical last character could never equal a computed pin).
     */
    public static function isValid(string $pin): bool
    {
        if (preg_match(self::PATTERN, $pin) !== 1) {
            return false;
        }
        $digest = base64_decode(substr($pin, 7), true);

        return $digest !== false && strlen($digest) === 32 && base64_encode($digest) === substr($pin, 7);
    }

    /**
     * Splits the form text into candidate pins: one per line (blank lines and `#` comments ignored), or a pasted
     * `pins.json` style list — a JSON array of strings or an object with a `pins` array. Duplicates are dropped and
     * parsing stops after {@see self::MAX_PINS} + 1 distinct entries (enough to report "too many"); the entries are
     * not validated (see {@see self::invalid()}).
     *
     * @return list<string>
     */
    public static function parse(string $text): array
    {
        $text = trim($text);
        if ($text === '') {
            return [];
        }

        $entries = null;
        if (str_starts_with($text, '[') || str_starts_with($text, '{')) {
            $decoded = json_decode($text, true);
            if (is_array($decoded) && array_key_exists('pins', $decoded)) {
                $decoded = $decoded['pins'];
            }
            if (is_array($decoded)) {
                $entries = [];
                foreach ($decoded as $entry) {
                    $entries[] = is_string($entry) ? trim($entry) : '';
                }
            }
        }
        if ($entries === null) {
            $entries = [];
            foreach (preg_split('/\R/', $text) ?: [] as $line) {
                $line = trim((string) preg_replace('/\s#.*$|^#.*$/', '', $line));
                if ($line !== '') {
                    $entries[] = $line;
                }
            }
        }

        $seen = [];
        foreach ($entries as $entry) {
            $seen[$entry] = true;
            if (count($seen) > self::MAX_PINS) {
                break;
            }
        }

        return array_map(strval(...), array_keys($seen));
    }

    /**
     * The entries that are not well-formed pins.
     *
     * @param list<string> $pins
     *
     * @return list<string>
     */
    public static function invalid(array $pins): array
    {
        $invalid = [];
        foreach ($pins as $pin) {
            if (!self::isValid($pin)) {
                $invalid[] = $pin;
            }
        }

        return $invalid;
    }

    /**
     * The pins stored in a monitor config (`expected_spki_pins`): `[]` when the key is missing, `null` or an empty
     * list (no pinning), the de-duplicated list when it holds 1 to {@see self::MAX_PINS} well-formed pins, and
     * `null` for anything else — a non-list, a malformed entry or too many pins — which the check treats as an
     * invalid config (fail closed, never unpinned).
     *
     * @param array<string, mixed> $config
     *
     * @return list<string>|null
     */
    public static function fromConfig(array $config): ?array
    {
        $stored = $config['expected_spki_pins'] ?? null;
        if ($stored === null || $stored === []) {
            return [];
        }
        if (!is_array($stored) || !array_is_list($stored) || count($stored) > self::MAX_PINS) {
            return null;
        }

        $pins = [];
        foreach ($stored as $pin) {
            if (!is_string($pin) || !self::isValid($pin)) {
                return null;
            }
            $pins[$pin] = true;
        }

        return array_keys($pins);
    }

    /**
     * The form text of a stored `expected_spki_pins` value (monitor edit, backup import): one pin per line, with
     * `?` for a malformed entry or a non-list value so the form validation refuses it instead of dropping it. At most
     * {@see self::MAX_PINS} + 1 entries are kept, enough for the "too many" error.
     */
    public static function toFormText(mixed $stored): string
    {
        if ($stored === null) {
            return '';
        }
        if (!is_array($stored)) {
            return '?';
        }

        $lines = [];
        foreach ($stored as $pin) {
            if (count($lines) > self::MAX_PINS) {
                break;
            }
            $lines[] = is_string($pin) && self::isValid($pin) ? $pin : '?';
        }

        return implode("\n", $lines);
    }

    /**
     * The `sha256/<base64>` pin of a certificate (an OpenSSLCertificate or PEM text; never a file path): SHA-256 of its
     * DER SubjectPublicKeyInfo, or null when the certificate or its public key cannot be read.
     */
    public static function fromCertificate(mixed $certificate): ?string
    {
        if (!$certificate instanceof OpenSSLCertificate && (!is_string($certificate) || !str_starts_with(trim($certificate), '-----BEGIN'))) {
            return null;
        }

        $key     = @openssl_pkey_get_public($certificate);
        $details = $key === false ? false : openssl_pkey_get_details($key);
        // `key` is the PEM "PUBLIC KEY" block, i.e. the base64 of the DER SubjectPublicKeyInfo.
        $pem = is_array($details) && is_string($details['key'] ?? null) ? $details['key'] : '';
        $der = base64_decode((string) preg_replace('/-----(BEGIN|END) PUBLIC KEY-----|\s+/', '', $pem), true);

        return $der === false || $der === '' ? null : 'sha256/' . base64_encode(hash('sha256', $der, true));
    }
}
