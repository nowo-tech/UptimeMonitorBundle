<?php

declare(strict_types=1);

namespace Nowo\UptimeMonitorBundle\Tests\Support;

use OpenSSLAsymmetricKey;
use OpenSSLCertificate;
use OpenSSLCertificateSigningRequest;
use RuntimeException;

use function openssl_csr_new;
use function openssl_csr_sign;
use function openssl_pkey_new;

use const OPENSSL_KEYTYPE_EC;

/**
 * Throwaway root → intermediate → leaf chain generated in memory (EC P-256 keys, nothing written to disk, no network).
 */
final readonly class TestCertificateChain
{
    private function __construct(
        public OpenSSLCertificate $root,
        public OpenSSLCertificate $intermediate,
        public OpenSSLCertificate $leaf,
    ) {
    }

    /**
     * Generates a fresh chain for `secure.example`.
     */
    public static function generate(): self
    {
        $rootKey         = self::key();
        $root            = self::sign(self::csr('Test Root CA', $rootKey), null, $rootKey);
        $intermediateKey = self::key();
        $intermediate    = self::sign(self::csr('Test Intermediate CA', $intermediateKey), $root, $rootKey);
        $leafKey         = self::key();
        $leaf            = self::sign(self::csr('secure.example', $leafKey), $intermediate, $intermediateKey);

        return new self($root, $intermediate, $leaf);
    }

    /**
     * The chain as served by a server that sends its root too (leaf first).
     *
     * @return list<OpenSSLCertificate>
     */
    public function served(): array
    {
        return [$this->leaf, $this->intermediate, $this->root];
    }

    /**
     * @return array<string, mixed>
     */
    private static function config(): array
    {
        return [
            'config'     => __DIR__ . '/../Fixtures/tls/openssl.cnf',
            'digest_alg' => 'sha256',
            // Ignored for EC keys, but PHP refuses a key length of 0 when the config file sets no default_bits.
            'private_key_bits' => 2048,
            'private_key_type' => OPENSSL_KEYTYPE_EC,
            'curve_name'       => 'prime256v1',
        ];
    }

    private static function key(): OpenSSLAsymmetricKey
    {
        $key = openssl_pkey_new(self::config());
        if (!$key instanceof OpenSSLAsymmetricKey) {
            throw new RuntimeException('Cannot generate a test key.');
        }

        return $key;
    }

    private static function csr(string $commonName, OpenSSLAsymmetricKey $key): OpenSSLCertificateSigningRequest
    {
        $csr = openssl_csr_new(['commonName' => $commonName], $key, self::config());
        if (!$csr instanceof OpenSSLCertificateSigningRequest) {
            throw new RuntimeException('Cannot create a test CSR.');
        }

        return $csr;
    }

    private static function sign(OpenSSLCertificateSigningRequest $csr, ?OpenSSLCertificate $issuer, OpenSSLAsymmetricKey $issuerKey): OpenSSLCertificate
    {
        $certificate = openssl_csr_sign($csr, $issuer, $issuerKey, 365, self::config());
        if (!$certificate instanceof OpenSSLCertificate) {
            throw new RuntimeException('Cannot sign a test certificate.');
        }

        return $certificate;
    }
}
