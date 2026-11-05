<?php

declare(strict_types=1);

namespace Nowo\UptimeMonitorBundle\Check\Network;

/**
 * Opens a client socket for TCP/TLS checks — the single network seam of the TCP, SSL and HTTPS certificate checks,
 * so the check logic is testable without real network access.
 */
interface SocketConnectorInterface
{
    /**
     * Connects to $remote (for example "tcp://203.0.113.10:443" or "ssl://203.0.113.10:443"), captures the peer
     * certificate (`capture_peer_cert`) and chain (`capture_peer_cert_chain`) when the context asks for them and closes
     * the socket.
     *
     * @param array<string, array<string, mixed>> $contextOptions Stream context options (for example ['ssl' => [...]])
     */
    public function connect(string $remote, float $timeout, array $contextOptions = []): SocketConnection;
}
