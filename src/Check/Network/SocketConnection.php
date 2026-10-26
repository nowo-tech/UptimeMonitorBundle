<?php

declare(strict_types=1);

namespace Nowo\UptimeMonitorBundle\Check\Network;

/**
 * Outcome of one {@see SocketConnectorInterface::connect()} call (the socket is already closed).
 */
final readonly class SocketConnection
{
    /**
     * @param bool $connected True when the (TCP or TLS) connection was established
     * @param int $errorCode errno reported by the stream layer on failure (0 when unknown)
     * @param string $errorMessage Error reported by the stream layer on failure ('' when unknown)
     * @param mixed $peerCertificate Peer certificate captured with ssl.capture_peer_cert (OpenSSLCertificate), or null
     */
    public function __construct(
        public bool $connected,
        public int $errorCode = 0,
        public string $errorMessage = '',
        public mixed $peerCertificate = null,
    ) {
    }
}
