<?php

declare(strict_types=1);

namespace Nowo\UptimeMonitorBundle\Monitor;

use OpenSSLCertificate;

use function array_key_exists;
use function base64_decode;
use function base64_encode;
use function count;
use function hash;
use function in_array;
use function is_array;
use function is_string;
use function json_decode;
use function openssl_pkey_get_details;
use function openssl_pkey_get_public;
use function preg_match;
use function preg_replace;
use function preg_split;
use function str_starts_with;
use function trim;

/**
 * SPKI pins of an SSL monitor (`expected_spki_pins`, since 1.20): `sha256/<base64>` hashes of a certificate's
 * SubjectPublicKeyInfo, the format of HPKP and of a `pins.json` list. The pins are public data, not secrets.
 */
final class SpkiPins
{
    /** Most pins an SSL monitor keeps. */
    public const MAX_PINS = 20;

    /** A SHA-256 digest (32 bytes) in standard base64 with its padding, prefixed `sha256/`. */
    public const PATTERN = '#^sha256/[A-Za-z0-9+/]{43}=$#D';

    /**
     * Whether the value is one well-formed `sha256/<base64>` pin.
     */
    public static function isValid(string $pin): bool
    {
        return preg_match(self::PATTERN, $pin) === 1;
    }

    /**
     * Splits the form text into candidate pins: one per line (blank lines and `#` comments ignored), or a pasted
     * `pins.json` style list — a JSON array of strings or an object with a `pins` array. Duplicates are dropped;
     * the entries are not validated (see {@see self::invalid()}).
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

        $pins = [];
        foreach ($entries as $entry) {
            if (!in_array($entry, $pins, true)) {
                $pins[] = $entry;
            }
        }

        return $pins;
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
     * The well-formed pins stored in a monitor config (`expected_spki_pins`), at most {@see self::MAX_PINS}; anything
     * else (a hand-edited or older config) is ignored.
     *
     * @param array<string, mixed> $config
     *
     * @return list<string>
     */
    public static function fromConfig(array $config): array
    {
        $stored = $config['expected_spki_pins'] ?? null;
        if (!is_array($stored)) {
            return [];
        }

        $pins = [];
        foreach ($stored as $pin) {
            if (is_string($pin) && self::isValid($pin) && !in_array($pin, $pins, true) && count($pins) < self::MAX_PINS) {
                $pins[] = $pin;
            }
        }

        return $pins;
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
