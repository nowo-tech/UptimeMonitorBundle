<?php

declare(strict_types=1);

/*
 * Test-only TLS server on 127.0.0.1 (random port) for StreamSocketConnectorTest: prints the port, accepts one
 * connection (completing the TLS handshake with the throwaway certificate/key passed as arguments) and exits.
 *
 * Usage: php server.php <certificate.pem> <private-key.pem>
 */
$context = stream_context_create(['ssl' => [
    'local_cert'  => $argv[1] ?? '',
    'local_pk'    => $argv[2] ?? '',
    'verify_peer' => false,
]]);

$server = stream_socket_server('ssl://127.0.0.1:0', $errno, $errstr, \STREAM_SERVER_BIND | \STREAM_SERVER_LISTEN, $context);
if ($server === false) {
    throw new RuntimeException((string) $errstr);
}

echo parse_url('tcp://' . stream_socket_get_name($server, false), \PHP_URL_PORT) . \PHP_EOL;

$client = @stream_socket_accept($server, 10);
if ($client !== false) {
    fclose($client);
}
fclose($server);
