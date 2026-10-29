<?php

declare(strict_types=1);

/** Acquire TLS and DNS batches concurrently while keeping evaluation and persistence separate. */

namespace App\Services\Monitoring\Checks;

use App\Contracts\Monitoring\CheckClock;
use App\Contracts\Monitoring\TlsTransport;
use App\Data\Monitoring\Checks\CheckInput;
use App\Data\Monitoring\Checks\CheckOutcome;
use App\Data\Monitoring\DnsMonitorConfiguration;
use App\Data\Monitoring\TlsMonitorConfiguration;
use App\Enums\Monitoring\CheckError;
use App\Enums\Monitoring\DnsRecordType;
use App\Enums\Monitoring\MonitorStatus;
use App\Exceptions\CheckTransportFailed;
use App\Exceptions\OutboundDestinationRejected;
use App\Services\Monitoring\MonitorInputLimits;
use App\Services\Monitoring\MonitorInputSyntax;
use App\Services\Security\OutboundDestinationGuard;
use App\Services\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use InvalidArgumentException;
use LogicException;
use React\EventLoop\LoopInterface;

use function React\Promise\all;

use React\Promise\PromiseInterface;

use function React\Promise\reject;
use function React\Promise\resolve;

use Throwable;

/** One shared orchestration path preserves whole-batch validation and per-observation budgets for both protocols. */
final readonly class NetworkCheckCoordinator
{
    /**
     * @param DnsLookup $dns Bounded alias-aware DNS acquisition.
     * @param DnsNameserver $servers System/custom resolver selection.
     * @param TlsTransport $tls Replaceable verified handshake adapter.
     * @param DnsStrategy $dnsStrategy Pure exact-set evaluator.
     * @param TlsStrategy $tlsStrategy Pure certificate expiry evaluator.
     * @param OutboundDestinationGuard $guard Shared SSRF boundary.
     * @param MonitorInputSyntax $syntax Shared configuration normalization.
     * @param MonitorInputLimits $limits Central operational bounds.
     * @param TenantContext $tenants Explicit authorized tenant lifetime.
     * @param CheckClock $clock Monotonic budget and latency source.
     * @param AsyncCheckRuntime $runtime Private event-loop bridge.
     */
    public function __construct(
        private DnsLookup $dns,
        private DnsNameserver $servers,
        private TlsTransport $tls,
        private DnsStrategy $dnsStrategy,
        private TlsStrategy $tlsStrategy,
        private OutboundDestinationGuard $guard,
        private MonitorInputSyntax $syntax,
        private MonitorInputLimits $limits,
        private TenantContext $tenants,
        private CheckClock $clock,
        private AsyncCheckRuntime $runtime,
    ) {
    }

    /**
     * @param array<array-key, mixed> $inputs Bounded list of private TLS/DNS configurations for the active tenant.
     * @return array<int, CheckOutcome> One safe result per execution ID, in input order.
     * @throws InvalidArgumentException When any batch member is invalid, before any network I/O.
     * @throws Throwable When programming or infrastructure setup fails, instead of inventing an outage.
     */
    public function run(#[\SensitiveParameter] array $inputs): array
    {
        $inputs = $this->validate($inputs);

        return $this->runtime->await(function (LoopInterface $loop) use ($inputs): PromiseInterface {
            $pending = [];

            try {
                foreach ($inputs as $input) {
                    $pending[$input->context->executionId] = $this->observe($input, $loop);
                }
            } catch (Throwable $error) {
                foreach ($pending as $operation) {
                    $operation->cancel();
                }

                throw $error;
            }

            return all($pending)->finally(static function () use ($pending): void {
                foreach ($pending as $operation) {
                    $operation->cancel();
                }
            });
        });
    }

    /**
     * @param array<array-key, mixed> $inputs Untrusted batch structure containing trusted private configurations.
     * @return list<CheckInput> Validated list with no unsupported variant or foreign identity.
     * @throws InvalidArgumentException When batch, identity, timeout or configuration is invalid.
     * @throws LogicException When the configured batch bound is invalid.
     */
    private function validate(array $inputs): array
    {
        $maximum = config('monitor-checks.max_batch_size');

        if (!is_int($maximum) || $maximum < 1) {
            throw new LogicException('Checker batch limits must be positive integers.');
        }

        if (!array_is_list($inputs) || count($inputs) > $maximum) {
            throw new InvalidArgumentException('Checker inputs must be a bounded list.');
        }

        $seen = [];

        foreach ($inputs as $input) {
            if (!$input instanceof CheckInput || $input->context->teamId !== $this->tenants->requireTeamId()
                || isset($seen[$input->context->executionId])) {
                throw new InvalidArgumentException('Checker inputs require distinct identities in the active tenant.');
            }

            $seen[$input->context->executionId] = true;

            if ($input->timeoutMs < $this->limits->get('timeout_min_ms') || $input->timeoutMs > $this->limits->get('timeout_max_ms')) {
                throw new InvalidArgumentException('The check timeout exceeds the operational bounds.');
            }

            $configuration = $input->configuration;

            if ($configuration instanceof DnsMonitorConfiguration) {
                $this->syntax->host($configuration->name, allowIp: false, allowUnderscore: true);
                $this->syntax->dnsValues($configuration->recordType, $configuration->expectedValues);

                if ($configuration->resolver !== null) {
                    $this->syntax->ip($configuration->resolver);
                }
            } elseif ($configuration instanceof TlsMonitorConfiguration) {
                $this->syntax->host($configuration->host);
            } else {
                throw new InvalidArgumentException('Unsupported or inconsistent network checker configuration.');
            }
        }

        return $inputs;
    }

    /**
     * @param CheckInput $input Fully validated variant and active tenant identity.
     * @param LoopInterface $loop Local concurrent batch lifetime.
     * @return PromiseInterface<CheckOutcome> Safe outcome for known failures; unknown exceptions reject the batch.
     */
    private function observe(CheckInput $input, LoopInterface $loop): PromiseInterface
    {
        $started = null;

        try {
            $configuration = $input->configuration;

            if ($configuration instanceof DnsMonitorConfiguration) {
                $server = $this->servers->select($configuration->resolver, $this->guard);
                $started = $this->clock->milliseconds();
                $deadline = $started + $input->timeoutMs;
                $name = $this->syntax->host($configuration->name, allowIp: false, allowUnderscore: true);
                $pending = $this->dns->records($name, $configuration->recordType, $server, $deadline, $loop)
                    ->then(fn (array $values): CheckOutcome => $this->dnsStrategy->evaluate($input, $values, CarbonImmutable::now('UTC'), $this->elapsed($started, $deadline)));
            } elseif ($configuration instanceof TlsMonitorConfiguration) {
                $host = $this->syntax->host($configuration->host);
                $this->guard->assertLocallyAllowed($host);
                $literal = filter_var($host, FILTER_VALIDATE_IP) !== false;
                $server = $literal ? null : $this->servers->system();
                $started = $this->clock->milliseconds();
                $deadline = $started + $input->timeoutMs;
                $addresses = $literal ? resolve([[$host], []]) : $this->addresses($host, (string) $server, $deadline, $loop);
                $pending = $addresses
                    ->then(function (array $answers) use ($host, $configuration, $deadline, $loop): PromiseInterface {
                        $destination = $this->guard->authorizeResolvedEndpoint($host, $configuration->port, [...$answers[0], ...$answers[1]]);

                        return $this->tls->inspect($destination, $deadline - $this->clock->milliseconds(), $loop);
                    })
                    ->then(fn (CarbonImmutable $expires): CheckOutcome => $this->tlsStrategy->evaluate($input, $expires, CarbonImmutable::now('UTC'), $this->elapsed($started, $deadline)));
            } else {
                throw new LogicException('Validated checker configuration changed unexpectedly.');
            }
        } catch (Throwable $error) {
            $pending = reject($error);
        }

        return $pending->catch(function (Throwable $error) use ($input, $started): CheckOutcome {
            $reason = match (true) {
                $error instanceof CheckTransportFailed => $error->reason,
                $error instanceof OutboundDestinationRejected => CheckError::from($error->reason->value),
                default => throw $error,
            };

            return new CheckOutcome($input->context, MonitorStatus::Down, CarbonImmutable::now('UTC'), $started === null ? null : max(0, $this->clock->milliseconds() - $started), null, $reason);
        });
    }

    /**
     * @param string $host Original peer name.
     * @param string $server Trusted system resolver endpoint.
     * @param float $deadline Cumulative observation deadline.
     * @param LoopInterface $loop Private event loop.
     * @return PromiseInterface<array<list<string>>> Complete A and AAAA answers, with cancellation propagated to both queries.
     */
    private function addresses(string $host, string $server, float $deadline, LoopInterface $loop): PromiseInterface
    {
        $queries = [
            $this->dns->records($host, DnsRecordType::A, $server, $deadline, $loop),
            $this->dns->records($host, DnsRecordType::AAAA, $server, $deadline, $loop),
        ];

        return all($queries)->finally(static function () use ($queries): void {
            foreach ($queries as $query) {
                $query->cancel();
            }
        });
    }

    /**
     * @param float $started Monotonic acquisition start.
     * @param float $deadline Exclusive completion deadline.
     * @return float Measured acquisition milliseconds.
     * @throws CheckTransportFailed When completion is at or beyond the deadline, even with a received response.
     */
    private function elapsed(float $started, float $deadline): float
    {
        $now = $this->clock->milliseconds();

        if ($now >= $deadline) {
            throw new CheckTransportFailed(CheckError::Timeout);
        }

        return max(0, $now - $started);
    }
}
