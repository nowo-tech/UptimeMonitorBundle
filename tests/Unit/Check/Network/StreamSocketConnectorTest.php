<?php

declare(strict_types=1);

namespace Nowo\UptimeMonitorBundle\Tests\Unit\Check\Network;

use Nowo\UptimeMonitorBundle\Check\Network\SocketConnection;
use Nowo\UptimeMonitorBundle\Check\Network\StreamSocketConnector;
use OpenSSLCertificate;
use OpenSSLCertificateSigningRequest;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

use function fclose;
use function fgets;
use function is_resource;
use function openssl_x509_parse;
use function proc_close;
use function proc_open;
use function sprintf;
use function stream_set_timeout;
use function stream_socket_get_name;
use function stream_socket_server;

use const OPENSSL_KEYTYPE_RSA;
use const PHP_BINARY;

/**
 * Loopback only (127.0.0.1): no external network access.
 */
#[CoversClass(StreamSocketConnector::class)]
#[CoversClass(SocketConnection::class)]
final class StreamSocketConnectorTest extends TestCase
{
    public function testConnectsToAListeningTcpSocket(): void
    {
        $server = stream_socket_server('tcp://127.0.0.1:0');
        self::assertNotFalse($server);
        $address = (string) stream_socket_get_name($server, false);

        $connection = (new StreamSocketConnector())->connect('tcp://' . $address, 2.0);
        fclose($server);

        self::assertTrue($connection->connected);
        self::assertSame(0, $connection->errorCode);
        self::assertNull($connection->peerCertificate);
    }

    public function testReportsTheErrorWhenNothingListens(): void
    {
        $server = stream_socket_server('tcp://127.0.0.1:0');
        self::assertNotFalse($server);
        $address = (string) stream_socket_get_name($server, false);
        fclose($server);

        $connection = (new StreamSocketConnector())->connect('tcp://' . $address, 2.0);

        self::assertFalse($connection->connected);
        self::assertNotSame('', $connection->errorMessage);
    }

    public function testCapturesThePeerCertificateOverTls(): void
    {
        [$certificateFile, $keyFile] = $this->throwawayCertificate();

        // Test-only helper process (loopback TLS server); it exits after one connection or a 10 s accept timeout.
        // @phpstan-ignore frankenphp.classic.noUnlimitedIoTimeout
        $process = proc_open(
            [PHP_BINARY, __DIR__ . '/../../../Fixtures/tls/server.php', $certificateFile, $keyFile],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
        );
        self::assertTrue(is_resource($process));
        stream_set_timeout($pipes[1], 10);
        $port = (int) fgets($pipes[1]);
        self::assertGreaterThan(0, $port, 'TLS test server did not start');

        $connection = (new StreamSocketConnector())->connect(sprintf('ssl://127.0.0.1:%d', $port), 5.0, [
            'ssl' => ['capture_peer_cert' => true, 'verify_peer' => false, 'verify_peer_name' => false],
        ]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_close($process);
        unlink($certificateFile);
        unlink($keyFile);

        self::assertTrue($connection->connected);
        self::assertInstanceOf(OpenSSLCertificate::class, $connection->peerCertificate);
        self::assertSame('localhost', openssl_x509_parse($connection->peerCertificate)['subject']['CN'] ?? null);
    }

    /**
     * Self-signed localhost certificate generated for this run only (no private key is committed).
     *
     * @return array{0: string, 1: string} certificate and private key file paths
     */
    private function throwawayCertificate(): array
    {
        $config = [
            'config'           => __DIR__ . '/../../../Fixtures/tls/openssl.cnf',
            'digest_alg'       => 'sha256',
            'private_key_bits' => 2048,
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
        ];

        $key = openssl_pkey_new($config);
        self::assertNotFalse($key);
        $csr = openssl_csr_new(['commonName' => 'localhost'], $key, $config);
        self::assertInstanceOf(OpenSSLCertificateSigningRequest::class, $csr);
        $certificate = openssl_csr_sign($csr, null, $key, 1, $config);
        self::assertNotFalse($certificate);

        $certificatePem = '';
        $keyPem         = '';
        self::assertTrue(openssl_x509_export($certificate, $certificatePem));
        self::assertTrue(openssl_pkey_export($key, $keyPem, null, $config));

        $certificateFile = (string) tempnam(sys_get_temp_dir(), 'uptime-tls-crt');
        $keyFile         = (string) tempnam(sys_get_temp_dir(), 'uptime-tls-key');
        file_put_contents($certificateFile, $certificatePem);
        file_put_contents($keyFile, $keyPem);

        return [$certificateFile, $keyFile];
    }
}
