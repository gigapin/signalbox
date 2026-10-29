<?php

declare(strict_types=1);

/** Track private per-observation transport state across bounded concurrent redirect rounds. */

namespace App\Services\Monitoring\Checks;

use App\Contracts\Monitoring\CheckClock;
use App\Data\Monitoring\Checks\CheckInput;
use App\Data\Monitoring\Checks\CheckOutcome;
use App\Data\Monitoring\HttpMonitorConfiguration;
use App\Data\Security\AuthorizedDestination;
use App\Enums\Monitoring\CheckError;
use App\Enums\Monitoring\HttpMethod;
use App\Enums\Monitoring\MonitorStatus;
use App\Services\Http\ResponseByteBudget;
use Carbon\CarbonImmutable;

/** No state object escapes as a public result or is serialized into queues. */
final class HttpCheckState
{
    /** @var HttpMethod Method for the current hop after redirect rewriting. */
    public HttpMethod $method;
    /** @var array<string, string> Remaining configured headers; removed credentials are never restored. */
    public array $headers;
    /** @var string|null Private current request body, cleared after a redirect rewrite. */
    public ?string $body;
    /** @var AuthorizedDestination|null Most recently authorized and pinned hop. */
    public ?AuthorizedDestination $destination = null;
    /** @var int Number of followed redirects. */
    public int $followed = 0;
    /** @var int|null Last received status on the current hop. */
    public ?int $statusCode = null;
    /** @var CarbonImmutable|null Last transport completion instant, absent before I/O. */
    public ?CarbonImmutable $observedAt = null;
    /** @var float|null Cumulative measured network milliseconds, excluding DNS and pool scheduling waits. */
    public ?float $latencyMs = null;
    /** @var float|null Monotonic start reading for the active network hop. */
    private ?float $startedMs = null;
    /** @var ResponseByteBudget Cumulative decoded response allowance. */
    public readonly ResponseByteBudget $bytes;

    /**
     * @param CheckInput $input Trusted identity and private validated variant.
     * @param HttpMonitorConfiguration $http Shared HTTP transport settings.
     * @param int $maxBytes Positive cumulative response byte cap.
     * @param CheckClock $clock Monotonic timing seam for real transfers and deterministic tests.
     */
    public function __construct(
        public readonly CheckInput $input,
        public readonly HttpMonitorConfiguration $http,
        int $maxBytes,
        private readonly CheckClock $clock,
    ) {
        $this->method = $http->method;
        $this->headers = $http->headers;
        $this->body = $http->body;
        $this->bytes = new ResponseByteBudget($maxBytes);
    }

    /** Mark the actual dispatch instant after DNS authorization and pool preparation. */
    public function begin(): void
    {
        $this->startedMs = $this->clock->milliseconds();
        $this->statusCode = null;
    }

    /** Accumulate one measurable transfer duration when the handler reports completion, including failures. */
    public function complete(): void
    {
        if ($this->startedMs !== null) {
            $this->latencyMs = ($this->latencyMs ?? 0.0) + max(0.0, $this->clock->milliseconds() - $this->startedMs);
            $this->startedMs = null;
            $this->observedAt = CarbonImmutable::now('UTC');
        }
    }

    /** @return float Remaining network budget in milliseconds; DNS and waits for unrelated responses are excluded. */
    public function remainingMs(): float
    {
        return max(0.0, $this->input->timeoutMs - ($this->latencyMs ?? 0.0));
    }

    /**
     * @param CheckError $error Stable safe classification; raw exceptions are never retained.
     * @return CheckOutcome Failed observation correlated to the original execution.
     */
    public function failure(CheckError $error): CheckOutcome
    {
        return new CheckOutcome(
            $this->input->context,
            MonitorStatus::Down,
            CarbonImmutable::now('UTC'),
            $this->latencyMs,
            $this->statusCode,
            $error,
        );
    }

    /** @return array<string, mixed> Safe debugging information without URLs, headers or bodies. */
    public function __debugInfo(): array
    {
        return ['context' => $this->input->context, 'followed' => $this->followed, 'latency_ms' => $this->latencyMs];
    }
}
