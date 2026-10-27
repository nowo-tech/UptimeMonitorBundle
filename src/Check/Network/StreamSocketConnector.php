<?php

declare(strict_types=1);

namespace Nowo\UptimeMonitorBundle\Check\Network;

use function fclose;
use function stream_context_create;
use function stream_context_get_params;
use function stream_socket_client;

use const STREAM_CLIENT_CONNECT;

/**
 * Default {@see SocketConnectorInterface} built on stream_socket_client().
 */
final readonly class StreamSocketConnector implements SocketConnectorInterface
{
    /**
     * Opens the connection with stream_socket_client(), captures the peer certificate the context asks for and closes it.
     *
     * @param array<string, array<string, mixed>> $contextOptions Stream context options (for example ['ssl' => [...]])
     */
    public function connect(string $remote, float $timeout, array $contextOptions = []): SocketConnection
    {
        $errno  = 0;
        $errstr = '';
        $client = @stream_socket_client(
            $remote,
            $errno,
            $errstr,
            $timeout,
            STREAM_CLIENT_CONNECT,
            stream_context_create($contextOptions),
        );

        if ($client === false) {
            return new SocketConnection(false, (int) $errno, (string) $errstr);
        }

        $params = stream_context_get_params($client);
        fclose($client);

        return new SocketConnection(true, peerCertificate: $params['options']['ssl']['peer_certificate'] ?? null);
    }
}
