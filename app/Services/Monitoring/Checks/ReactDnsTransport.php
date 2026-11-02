<?php

declare(strict_types=1);

/** Send uncached DNS queries with one cumulative UDP/TCP timeout and no automatic retry. */

namespace App\Services\Monitoring\Checks;

use App\Contracts\Monitoring\DnsTransport;
use App\Enums\Monitoring\CheckError;
use App\Exceptions\CheckTransportFailed;
use React\Dns\Model\Message;
use React\Dns\Query\Query;
use React\Dns\Query\SelectiveTransportExecutor;
use React\Dns\Query\TcpTransportExecutor;
use React\Dns\Query\UdpTransportExecutor;
use React\EventLoop\LoopInterface;
use React\Promise\PromiseInterface;

use function React\Promise\reject;
use function React\Promise\Timer\timeout;

use React\Promise\Timer\TimeoutException;
use RuntimeException;
use Throwable;

/** TCP is a protocol continuation for truncated UDP answers, never a retry after an ordinary failure. */
final class ReactDnsTransport implements DnsTransport
{
    /**
     * @param string $name Absolute query name.
     * @param int $type DNS wire record family.
     * @param string $server Literal nameserver endpoint authorized by the caller.
     * @param float $timeoutMs Remaining cumulative milliseconds.
     * @param LoopInterface $loop Local event loop.
     * @return PromiseInterface<Message> Safe cancellable response.
     */
    public function query(string $name, int $type, string $server, float $timeoutMs, LoopInterface $loop): PromiseInterface
    {
        if ($timeoutMs <= 0) {
            return reject(new CheckTransportFailed(CheckError::Timeout));
        }

        $tcp = new TcpTransportExecutor($server, $loop);
        $executor = new SelectiveTransportExecutor(new UdpTransportExecutor($server, $loop), $tcp);

        // React's encoder appends the root label itself; a trailing dot would corrupt the question's type and class.
        $question = new Query(rtrim($name, '.'), $type, Message::CLASS_IN);

        return timeout($executor->query($question), $timeoutMs / 1000, $loop)
            ->catch(static function (Throwable $error): never {
                if ($error instanceof TimeoutException) {
                    throw new CheckTransportFailed(CheckError::Timeout);
                }

                if ($error instanceof RuntimeException) {
                    throw new CheckTransportFailed(CheckError::DnsResponse);
                }

                throw $error;
            });
    }
}
