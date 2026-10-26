<?php

declare(strict_types=1);

/** Serve synthetic HTTP responses through a nonblocking event loop with an explicit two-request barrier. */

$listener = stream_socket_server('tcp://127.0.0.1:0', $errorNumber, $errorMessage);

if ($listener === false) {
    throw new RuntimeException('Cannot create the synthetic check server.');
}

stream_set_blocking($listener, false);
fwrite(STDOUT, 'READY '.stream_socket_get_name($listener, false)."\n");
fflush(STDOUT);
$clients = [];
$buffers = [];
$barrier = [];
$until = microtime(true) + 15;

/**
 * @param resource $client Connected synthetic test socket.
 * @param string $body Exact wire response body, compressed when the header says so.
 * @param array<string, string> $headers Additional synthetic response headers.
 */
function sendCheckProbeResponse($client, string $body, array $headers = []): void
{
    $lines = ['HTTP/1.1 200 OK', 'Content-Length: '.strlen($body), 'Connection: close'];

    foreach ($headers as $name => $value) {
        $lines[] = $name.': '.$value;
    }

    $data = implode("\r\n", $lines)."\r\n\r\n".$body;
    stream_set_blocking($client, true);
    stream_set_timeout($client, 1);

    while ($data !== '') {
        $written = @fwrite($client, $data);

        if ($written === false || $written === 0) {
            break;
        }

        $data = substr($data, $written);
    }

    fclose($client);
}

while (microtime(true) < $until) {
    $read = [$listener, ...array_values($clients)];
    $write = null;
    $except = null;

    if (stream_select($read, $write, $except, 0, 200000) === false) {
        break;
    }

    foreach ($read as $socket) {
        if ($socket === $listener) {
            $client = @stream_socket_accept($listener, 0);

            if ($client !== false) {
                stream_set_blocking($client, false);
                $clients[(int) $client] = $client;
                $buffers[(int) $client] = '';
            }

            continue;
        }

        $id = (int) $socket;
        $chunk = fread($socket, 8192);

        if ($chunk === false || ($chunk === '' && feof($socket))) {
            unset($clients[$id], $buffers[$id], $barrier[$id]);
            fclose($socket);

            continue;
        }

        $buffers[$id] .= $chunk;

        if (!str_contains($buffers[$id], "\r\n\r\n")) {
            continue;
        }

        preg_match('#^[A-Z]+ ([^ ]+) HTTP/#', $buffers[$id], $matches);
        $path = $matches[1] ?? '/';
        file_put_contents($argv[1], $path."\n", FILE_APPEND | LOCK_EX);
        unset($clients[$id], $buffers[$id]);

        if (str_starts_with($path, '/barrier-')) {
            $barrier[$id] = $socket;

            if (count($barrier) === 2) {
                foreach ($barrier as $waiting) {
                    sendCheckProbeResponse($waiting, 'both-arrived');
                }

                $barrier = [];
            }
        } elseif ($path === '/gzip') {
            $body = gzencode(str_repeat('x', 1048577));
            sendCheckProbeResponse($socket, $body === false ? '' : $body, ['Content-Encoding' => 'gzip']);
        } elseif ($path === '/hang') {
            // Hold this socket without delaying the event loop or any other synthetic observation.
            $barrier[$id] = $socket;
        } else {
            sendCheckProbeResponse($socket, 'healthy');
        }
    }
}

foreach ([...array_values($clients), ...array_values($barrier)] as $client) {
    if (is_resource($client)) {
        fclose($client);
    }
}
fclose($listener);
