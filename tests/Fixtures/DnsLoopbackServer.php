<?php

declare(strict_types=1);

/** Serve synthetic DNS over UDP/TCP, with a two-query barrier, truncation and deliberate silence. */

require dirname(__DIR__, 2).'/vendor/autoload.php';

use React\Dns\Model\Message;
use React\Dns\Model\Record;
use React\Dns\Protocol\BinaryDumper;
use React\Dns\Protocol\Parser;

$tcp = stream_socket_server('tcp://127.0.0.1:0', $errorNumber, $errorMessage);

if ($tcp === false) {
    throw new RuntimeException('Cannot create synthetic DNS TCP listener.');
}

$endpoint = stream_socket_get_name($tcp, false);
$udp = stream_socket_server('udp://'.$endpoint, $errorNumber, $errorMessage, STREAM_SERVER_BIND);

if ($udp === false) {
    throw new RuntimeException('Cannot create synthetic DNS UDP listener.');
}

stream_set_blocking($tcp, false);
stream_set_blocking($udp, false);
fwrite(STDOUT, 'READY '.$endpoint."\n");
fflush(STDOUT);
$clients = [];
$buffers = [];
$barrier = [];
$parser = new Parser();
$dumper = new BinaryDumper();
$until = microtime(true) + 15;

/**
 * @param Message $request Parsed synthetic client query.
 * @param bool $truncated Whether UDP must request a TCP continuation.
 * @return Message Wire response retaining the original transaction ID and question.
 */
function dnsProbeReply(Message $request, bool $truncated): Message
{
    $reply = Message::createResponseWithAnswersForQuery($request->questions[0], []);
    $reply->id = $request->id;
    $reply->tc = $truncated;

    if (!$truncated) {
        $reply->answers[] = new Record($request->questions[0]->name, Message::TYPE_A, Message::CLASS_IN, 60, '8.8.8.8');
    }

    return $reply;
}

while (microtime(true) < $until) {
    $read = [$tcp, $udp, ...array_values($clients)];
    $write = null;
    $except = null;

    if (@stream_select($read, $write, $except, 0, 100000) === false) {
        break;
    }

    foreach ($read as $socket) {
        if ($socket === $tcp) {
            $client = @stream_socket_accept($tcp, 0);

            if ($client !== false) {
                stream_set_blocking($client, false);
                $clients[(int) $client] = $client;
                $buffers[(int) $client] = '';
            }

            continue;
        }

        if ($socket === $udp) {
            $data = stream_socket_recvfrom($udp, 65535, 0, $peer);
        } else {
            $id = (int) $socket;
            $chunk = fread($socket, 65535);

            if ($chunk === false || ($chunk === '' && feof($socket))) {
                unset($clients[$id], $buffers[$id]);
                fclose($socket);

                continue;
            }

            $buffers[$id] .= $chunk;

            if (strlen($buffers[$id]) < 2) {
                continue;
            }

            $length = unpack('nlength', $buffers[$id])['length'];

            if (strlen($buffers[$id]) < $length + 2) {
                continue;
            }

            $data = substr($buffers[$id], 2, $length);
            $buffers[$id] = substr($buffers[$id], $length + 2);
        }

        if (!is_string($data) || $data === '') {
            continue;
        }

        $request = $parser->parseMessage($data);
        $name = $request->questions[0]->name;
        file_put_contents($argv[1], ($socket === $udp ? 'udp ' : 'tcp ').$name."\n", FILE_APPEND | LOCK_EX);

        if ($name === 'hang.example.test') {
            continue;
        }

        if (str_starts_with($name, 'barrier')) {
            $barrier[] = [$request, $peer];

            if (count($barrier) === 2) {
                foreach ($barrier as [$waiting, $remote]) {
                    stream_socket_sendto($udp, $dumper->toBinary(dnsProbeReply($waiting, false)), 0, $remote);
                }

                $barrier = [];
            }

            continue;
        }

        $reply = $dumper->toBinary(dnsProbeReply($request, $name === 'tcp.example.test' && $socket === $udp));

        if ($socket === $udp) {
            stream_socket_sendto($udp, $reply, 0, $peer);
        } else {
            fwrite($socket, pack('n', strlen($reply)).$reply);
        }
    }
}

foreach ($clients as $client) {
    fclose($client);
}
fclose($udp);
fclose($tcp);
