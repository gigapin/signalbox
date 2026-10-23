<?php

declare(strict_types=1);

/** Acquire HTTP and keyword observations together without persisting results or starting the scheduler. */

namespace App\Services\Monitoring\Checks;

use App\Contracts\Monitoring\CheckClock;
use App\Data\Monitoring\Checks\CheckInput;
use App\Data\Monitoring\Checks\CheckOutcome;
use App\Data\Monitoring\Checks\HttpObservation;
use App\Enums\Monitoring\CheckError;
use App\Enums\Monitoring\HttpMethod;
use App\Services\Monitoring\MonitorInputLimits;
use App\Services\Security\GuardedHttpOptions;
use App\Services\Security\OutboundDestinationGuard;
use App\Services\Security\RedirectPolicy;
use App\Services\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use GuzzleHttp\TransferStats;
use Illuminate\Http\Client\Pool;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use InvalidArgumentException;
use LogicException;
use Throwable;

/** Every public input is validated before I/O; pools retain explicit execution IDs through out-of-order completion. */
final readonly class HttpCheckCoordinator
{
    /**
     * @param HttpStrategyResolver $strategies Explicit configuration resolver and pure evaluators.
     * @param OutboundDestinationGuard $guard Access-time address authorization.
     * @param GuardedHttpOptions $options Non-overridable pinning/proxy/redirect safeguards.
     * @param RedirectPolicy $redirects Reauthorization, downgrade and origin policy.
     * @param HttpTransportErrors $errors Safe known-failure classification.
     * @param CheckClock $clock Monotonic network measurement.
     * @param MonitorInputLimits $inputLimits Central configured network timeout bounds.
     * @param TenantContext $tenants Explicit admitted tenant lifetime supplied by the caller.
     */
    public function __construct(
        private HttpStrategyResolver $strategies,
        private OutboundDestinationGuard $guard,
        private GuardedHttpOptions $options,
        private RedirectPolicy $redirects,
        private HttpTransportErrors $errors,
        private CheckClock $clock,
        private MonitorInputLimits $inputLimits,
        private TenantContext $tenants,
    ) {
    }

    /**
     * @param array<array-key, mixed> $inputs Private admitted observations, at most ten, belonging to the active tenant.
     * @return array<int, CheckOutcome> Exactly one safe outcome per execution ID, in input order.
     * @throws InvalidArgumentException When batch size, identity, tenant or configuration is inconsistent.
     * @throws Throwable On programming or infrastructure setup failures, rather than inventing an outage.
     */
    public function run(#[\SensitiveParameter] array $inputs): array
    {
        $states = $this->prepare($inputs);
        $results = [];
        $active = [];

        foreach ($states as $id => $state) {
            try {
                $state->destination = $this->guard->authorizeUrl($state->http->url);
                $active[$id] = $state;
            } catch (Throwable $error) {
                $results[$id] = $state->failure($this->errors->classify($error));
            }
        }

        while ($active !== []) {
            $buffers = [];

            try {
                $responses = Http::pool(function (Pool $pool) use ($active, &$buffers): void {
                    foreach ($active as $id => $state) {
                        $buffers[$id] = new BoundedResponseBuffer($state->bytes);
                        $this->send($pool, $state, $buffers[$id]);
                    }
                });

                $next = [];

                foreach ($active as $id => $state) {
                    $response = $responses[(string) $id] ?? throw new LogicException('The HTTP pool lost an execution identity.');
                    $outcome = $this->observe($state, $response, $buffers[$id]);

                    if ($outcome !== null) {
                        $results[$id] = $outcome;
                    } else {
                        $next[$id] = $state;
                    }
                }

                $active = $next;
            } finally {
                foreach ($buffers as $buffer) {
                    $buffer->close();
                }
            }
        }

        $ordered = [];

        foreach ($states as $id => $state) {
            $ordered[$id] = $results[$id];
        }

        return $ordered;
    }

    /**
     * @param array<array-key, mixed> $inputs Entire batch, checked before resolving any host or sending any traffic.
     * @return array<int, HttpCheckState> Private state indexed by execution ID.
     * @throws InvalidArgumentException On malformed batches, foreign ownership or unsupported configurations.
     */
    private function prepare(array $inputs): array
    {
        $maximum = config('monitor-checks.max_batch_size');
        $bytes = config('monitor-checks.max_response_bytes');

        if (!is_int($maximum) || $maximum < 1 || !is_int($bytes) || $bytes < 1) {
            throw new LogicException('Checker limits must be positive integers.');
        }

        if (!array_is_list($inputs) || count($inputs) > $maximum) {
            throw new InvalidArgumentException('Checker inputs must be a bounded list.');
        }

        $states = [];

        foreach ($inputs as $input) {
            if (!$input instanceof CheckInput || $input->context->teamId !== $this->tenants->requireTeamId()
                || isset($states[$input->context->executionId])) {
                throw new InvalidArgumentException('Checker inputs require distinct identities in the active tenant.');
            }

            if ($input->timeoutMs < $this->inputLimits->get('timeout_min_ms')
                || $input->timeoutMs > $this->inputLimits->get('timeout_max_ms')) {
                throw new InvalidArgumentException('The check timeout exceeds the configured operational bounds.');
            }

            $http = $this->strategies->transport($input->configuration);
            $states[$input->context->executionId] = new HttpCheckState($input, $http, $bytes, $this->clock);
        }

        return $states;
    }

    /**
     * @param Pool $pool Laravel concurrent HTTP pool for this redirect round.
     * @param HttpCheckState $state Independently budgeted observation.
     * @param BoundedResponseBuffer $buffer Enforcing sink for the current response.
     */
    private function send(Pool $pool, HttpCheckState $state, BoundedResponseBuffer $buffer): void
    {
        $destination = $state->destination ?? throw new LogicException('A request requires an authorized destination.');
        $seconds = max(0.001, $state->remainingMs() / 1000);
        $pool->as((string) $state->input->context->executionId)
            ->withOptions([
                ...$this->options->for($destination),
                'cookies' => false,
                'verify' => true,
                'decode_content' => true,
                'sink' => $buffer->sink(),
                'on_stats' => static function (TransferStats $stats) use ($state): void {
                    $state->complete();
                },
            ])
            ->withHeaders($state->headers)
            ->timeout($seconds)
            ->connectTimeout($seconds)
            ->beforeSending(static function () use ($state): void {
                $state->begin();
            })
            ->send($state->method->value, (string) $destination->url, ['body' => $state->body ?? '']);
    }

    /**
     * @param HttpCheckState $state Current correlated observation.
     * @param mixed $response Pool response or rejected transport exception.
     * @param BoundedResponseBuffer $buffer Decoded current-hop content.
     * @return CheckOutcome|null Terminal safe result, or null when a validated next hop is ready.
     * @throws Throwable On an unknown exception or invalid transport result.
     */
    private function observe(HttpCheckState $state, mixed $response, BoundedResponseBuffer $buffer): ?CheckOutcome
    {
        if ($state->bytes->exceeded) {
            return $state->failure(CheckError::ResponseTooLarge);
        }

        if ($response instanceof Throwable) {
            return $state->failure($this->errors->classify($response));
        }

        if (!$response instanceof Response) {
            throw new LogicException('The HTTP pool returned an invalid observation.');
        }

        $state->statusCode = $response->status();

        if ($state->remainingMs() <= 0) {
            return $state->failure(CheckError::Timeout);
        }

        if ($state->http->followRedirects && in_array($response->status(), [301, 302, 303, 307, 308], true)) {
            try {
                return $this->redirect($state, $response);
            } catch (Throwable $error) {
                return $state->failure($this->errors->classify($error));
            }
        }

        $observation = new HttpObservation(
            $response->status(),
            $buffer->contents(),
            $state->observedAt ?? CarbonImmutable::now('UTC'),
            $state->latencyMs,
        );

        return $this->strategies->resolve($state->input->configuration)->evaluate($state->input, $observation);
    }

    /**
     * @param HttpCheckState $state Private current-hop state, rewritten only after redirect authorization.
     * @param Response $response Received redirect response.
     * @return CheckOutcome|null Refusal, or null after preparing the next bounded hop.
     */
    private function redirect(HttpCheckState $state, Response $response): ?CheckOutcome
    {
        $location = $response->header('Location');

        if (trim($location) === '') {
            return $state->failure(CheckError::InvalidRedirect);
        }

        $destination = $state->destination ?? throw new LogicException('Redirects require a current origin.');
        $step = $this->redirects->next($destination, $location, $state->followed);
        $rewrite = ($response->status() === 303 && $state->method !== HttpMethod::HEAD)
            || (in_array($response->status(), [301, 302], true) && $state->method === HttpMethod::POST);

        if ($rewrite) {
            $state->method = HttpMethod::GET;
            $state->body = null;
            $state->headers = array_filter(
                $state->headers,
                static fn (string $name): bool => !str_starts_with(strtolower($name), 'content-')
                    && strtolower($name) !== 'expect',
                ARRAY_FILTER_USE_KEY,
            );
        }

        if ($step->crossOrigin && $state->body !== null && $state->body !== '') {
            return $state->failure(CheckError::CrossOriginBody);
        }

        $state->headers = $step->headers($state->headers);
        $state->destination = $step->destination;
        $state->followed++;

        return null;
    }
}
