<?php

declare(strict_types=1);

/** Serve only synthetic TLS handshakes, optionally waiting for two TCP clients before either handshake. */

$context = stream_context_create(['ssl' => ['local_cert' => $argv[1], 'verify_peer' => false]]);
$listener = stream_socket_server('tcp://127.0.0.1:0', $errorNumber, $errorMessage, STREAM_SERVER_BIND | STREAM_SERVER_LISTEN, $context);

if ($listener === false) {
    throw new RuntimeException('Cannot start the synthetic TLS server.');
}

stream_set_blocking($listener, false);
fwrite(STDOUT, 'READY '.stream_socket_get_name($listener, false)."\n");
fflush(STDOUT);
$clients = [];
$accepted = 0;
$until = microtime(true) + 15;

while (microtime(true) < $until) {
    $read = [$listener, ...array_values($clients)];
    $write = null;
    $except = null;

    if (@stream_select($read, $write, $except, 0, 100000) === false) {
        break;
    }

    foreach ($read as $socket) {
        if ($socket === $listener) {
            $client = @stream_socket_accept($listener, 0);

            if ($client !== false) {
                stream_set_blocking($client, false);
                $clients[(int) $client] = $client;
                $accepted++;
                file_put_contents($argv[3], "accepted\n", FILE_APPEND | LOCK_EX);
            }

            continue;
        }

        if ($argv[2] === 'hang' || ($argv[2] === 'barrier' && $accepted < 2)) {
            continue;
        }

        $ready = @stream_socket_enable_crypto($socket, true, STREAM_CRYPTO_METHOD_TLS_SERVER);

        if ($ready === 0) {
            continue;
        }

        unset($clients[(int) $socket]);
        fclose($socket);
    }
}

foreach ($clients as $client) {
    fclose($client);
}
fclose($listener);
