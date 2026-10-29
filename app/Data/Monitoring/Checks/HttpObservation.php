<?php

declare(strict_types=1);

/** Carry a bounded private HTTP response only as far as its pure evaluator. */

namespace App\Data\Monitoring\Checks;

use Carbon\CarbonImmutable;

/** Raw response content is not part of the public check outcome. */
final readonly class HttpObservation
{
    /**
     * @param int $statusCode Received final status.
     * @param string $body Complete decoded body within the observation's cumulative byte cap.
     * @param CarbonImmutable $observedAt UTC transport completion instant.
     * @param float|null $latencyMs Measured network milliseconds, nullable if the transport supplied no measurement.
     */
    public function __construct(
        public int $statusCode,
        #[\SensitiveParameter]
        public string $body,
        public CarbonImmutable $observedAt,
        public ?float $latencyMs,
    ) {
    }

    /** @return array<string, mixed> Debug information excluding private body bytes. */
    public function __debugInfo(): array
    {
        return ['status_code' => $this->statusCode, 'body_bytes' => strlen($this->body), 'latency_ms' => $this->latencyMs];
    }
}
