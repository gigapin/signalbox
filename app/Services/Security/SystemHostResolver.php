<?php

declare(strict_types=1);

/** Resolve A/AAAA concurrently through trusted infrastructure with a cumulative deadline. */

namespace App\Services\Security;

use App\Contracts\Monitoring\CheckClock;
use App\Contracts\Security\HostResolver;
use App\Enums\Monitoring\CheckError;
use App\Enums\Monitoring\DnsRecordType;
use App\Exceptions\CheckTransportFailed;
use App\Services\Monitoring\Checks\AsyncCheckRuntime;
use App\Services\Monitoring\Checks\DnsLookup;
use App\Services\Monitoring\Checks\DnsNameserver;
use LogicException;
use React\EventLoop\LoopInterface;

use function React\Promise\all;

use React\Promise\PromiseInterface;

/** Search domains, hosts-file aliases, caches and automatic retries are excluded. */
final readonly class SystemHostResolver implements HostResolver
{
    /**
     * @param DnsLookup $lookup Shared normalized DNS acquisition.
     * @param DnsNameserver $servers Trusted system resolver selection.
     * @param CheckClock $clock Monotonic deadline source.
     * @param AsyncCheckRuntime $runtime Private event-loop bridge for the synchronous guard.
     */
    public function __construct(private DnsLookup $lookup, private DnsNameserver $servers, private CheckClock $clock, private AsyncCheckRuntime $runtime)
    {
    }

    /**
     * @param string $host Normalized ASCII hostname without a terminal dot.
     * @param int|null $timeoutMs Optional positive cumulative lookup budget in milliseconds.
     * @return list<string> Canonical addresses, or none after a recognized DNS failure.
     * @throws CheckTransportFailed When the resolution deadline expires.
     * @throws LogicException When resolver configuration is missing or malformed.
     */
    public function resolve(string $host, ?int $timeoutMs = null): array
    {
        $budget = $timeoutMs ?? config('monitor-checks.resolution_timeout_ms');

        if (!is_int($budget) || $budget < 1) {
            throw new LogicException('DNS resolution requires a positive millisecond budget.');
        }

        $server = $this->servers->system();
        $deadline = $this->clock->milliseconds() + $budget;

        try {
            $answers = $this->runtime->await(function (LoopInterface $loop) use ($host, $server, $deadline): PromiseInterface {
                $queries = [
                    $this->lookup->records($host, DnsRecordType::A, $server, $deadline, $loop),
                    $this->lookup->records($host, DnsRecordType::AAAA, $server, $deadline, $loop),
                ];

                return all($queries)->finally(static function () use ($queries): void {
                    foreach ($queries as $query) {
                        $query->cancel();
                    }
                });
            });
        } catch (CheckTransportFailed $error) {
            if ($error->reason === CheckError::Timeout) {
                throw $error;
            }

            return [];
        }

        return array_values(array_unique([...$answers[0], ...$answers[1]]));
    }
}
