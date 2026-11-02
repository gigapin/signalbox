<?php

declare(strict_types=1);

/** Verify the exact DNS TXT proof through the existing bounded, cancellable DNS transport. */

namespace App\Services\StatusPages;

use App\Contracts\Monitoring\CheckClock;
use App\Enums\Monitoring\DnsRecordType;
use App\Exceptions\CheckTransportFailed;
use App\Services\Monitoring\Checks\AsyncCheckRuntime;
use App\Services\Monitoring\Checks\DnsLookup;
use App\Services\Monitoring\Checks\DnsNameserver;
use LogicException;
use React\EventLoop\LoopInterface;
use React\Promise\PromiseInterface;

/** Customer-controlled values affect only the DNS query name; no HTTP/TLS connection to that name is made. */
final readonly class StatusDomainProof
{
    /**
     * @param DnsLookup $lookup Validates question, answer owners, aliases and concatenated TXT segments.
     * @param DnsNameserver $servers Trusted system resolver, never a customer-supplied destination.
     * @param CheckClock $clock Monotonic deadline source.
     * @param AsyncCheckRuntime $runtime Isolated synchronous-to-async bridge.
     */
    public function __construct(private DnsLookup $lookup, private DnsNameserver $servers, private CheckClock $clock, private AsyncCheckRuntime $runtime)
    {
    }

    /**
     * @param string $hostname Canonical customer DNS name.
     * @param string $challenge Current random page generation.
     * @return bool Exact proof present; false on absence, malformed answers or transport failure.
     */
    public function matches(string $hostname, string $challenge): bool
    {
        $budget = config('status-domains.dns_timeout_ms');
        if (!is_int($budget) || $budget < 1 || $budget > 5000) {
            throw new LogicException('Domain proof requires a DNS budget between 1 and 5000 milliseconds.');
        }

        try {
            $server = $this->servers->system();
            $deadline = $this->clock->milliseconds() + $budget;
            $records = $this->runtime->await(fn (LoopInterface $loop): PromiseInterface => $this->lookup->records(
                '_signalbox.'.$hostname,
                DnsRecordType::TXT,
                $server,
                $deadline,
                $loop,
            ));
        } catch (CheckTransportFailed|LogicException) {
            return false;
        }

        return in_array('signalbox-verification='.$challenge, $records, true);
    }
}
