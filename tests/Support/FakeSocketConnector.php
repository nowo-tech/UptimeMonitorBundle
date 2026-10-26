<?php

declare(strict_types=1);

namespace Nowo\UptimeMonitorBundle\Tests\Support;

use Nowo\UptimeMonitorBundle\Check\Network\SocketConnection;
use Nowo\UptimeMonitorBundle\Check\Network\SocketConnectorInterface;
use OpenSSLCertificate;
use RuntimeException;

use function file_get_contents;
use function openssl_x509_read;

/**
 * Records connect() calls and answers with a fixed {@see SocketConnection} (no network).
 */
final class FakeSocketConnector implements SocketConnectorInterface
{
    /** @var list<array{remote: string, timeout: float, options: array<string, array<string, mixed>>}> */
    public array $calls = [];

    public function __construct(private readonly SocketConnection $connection)
    {
    }

    public function connect(string $remote, float $timeout, array $contextOptions = []): SocketConnection
    {
        $this->calls[] = ['remote' => $remote, 'timeout' => $timeout, 'options' => $contextOptions];

        return $this->connection;
    }

    /**
     * The self-signed localhost test certificate (tests/Fixtures/tls, valid until 2126-10-02 19:33:06 UTC).
     */
    public static function fixtureCertificate(): OpenSSLCertificate
    {
        $certificate = openssl_x509_read((string) file_get_contents(__DIR__ . '/../Fixtures/tls/localhost.crt'));
        if (!$certificate instanceof OpenSSLCertificate) {
            throw new RuntimeException('Cannot read the TLS fixture certificate.');
        }

        return $certificate;
    }
}
