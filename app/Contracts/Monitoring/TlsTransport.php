<?php

declare(strict_types=1);

/** Isolate verified TLS handshakes from pure certificate expiry evaluation. */

namespace App\Contracts\Monitoring;

use App\Data\Security\AuthorizedDestination;
use Carbon\CarbonImmutable;
use React\EventLoop\LoopInterface;
use React\Promise\PromiseInterface;

/** Only a destination authorized by the outbound guard may be passed to this adapter. */
interface TlsTransport
{
    /**
     * @param AuthorizedDestination $destination Original peer identity and authorized IP pins.
     * @param float $timeoutMs Remaining budget for TCP connection and TLS handshake.
     * @param LoopInterface $loop Batch-owned event loop.
     * @return PromiseInterface<CarbonImmutable> Verified leaf certificate expiry in UTC.
     */
    public function inspect(AuthorizedDestination $destination, float $timeoutMs, LoopInterface $loop): PromiseInterface;
}
