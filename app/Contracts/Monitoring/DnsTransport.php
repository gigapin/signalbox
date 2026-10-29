<?php

declare(strict_types=1);

/** Isolate cancellable DNS wire I/O from alias interpretation and record evaluation. */

namespace App\Contracts\Monitoring;

use React\Dns\Model\Message;
use React\EventLoop\LoopInterface;
use React\Promise\PromiseInterface;

/** The caller authorizes custom nameservers; implementations never select fallback servers. */
interface DnsTransport
{
    /**
     * @param string $name Absolute ASCII query name, without search-domain expansion.
     * @param int $type DNS wire record family.
     * @param string $server Trusted or authorized literal nameserver endpoint, including port.
     * @param float $timeoutMs Remaining cumulative budget in milliseconds.
     * @param LoopInterface $loop Batch-owned event loop.
     * @return PromiseInterface<Message> Cancellable response or a safe CheckTransportFailed rejection.
     */
    public function query(string $name, int $type, string $server, float $timeoutMs, LoopInterface $loop): PromiseInterface;
}
