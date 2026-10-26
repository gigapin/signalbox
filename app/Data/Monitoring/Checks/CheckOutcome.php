<?php

declare(strict_types=1);

/** Return one safe normalized observation without persistence or incident side effects. */

namespace App\Data\Monitoring\Checks;

use App\Enums\Monitoring\CheckError;
use App\Enums\Monitoring\MonitorStatus;
use Carbon\CarbonImmutable;
use InvalidArgumentException;

/** No request URL, header, body, Location or raw exception enters this result. */
final readonly class CheckOutcome
{
    /**
     * @param CheckContext $context Correlation identity supplied by the admitted caller.
     * @param MonitorStatus $status Up for matched expectations, down for an observed check failure.
     * @param CarbonImmutable $observedAt UTC completion or refusal instant.
     * @param float|null $latencyMs Measured cumulative network milliseconds; null before any measurable transport.
     * @param int|null $statusCode Last received HTTP status, absent when no response was received on the current hop.
     * @param CheckError|null $error Stable safe classification, required only for failures.
     * @param CarbonImmutable|null $tlsExpiresAt Verified leaf certificate expiry in UTC, absent for other protocols or failed handshakes.
     * @throws InvalidArgumentException When outcome fields contradict their invariants.
     */
    public function __construct(
        public CheckContext $context,
        public MonitorStatus $status,
        public CarbonImmutable $observedAt,
        public ?float $latencyMs,
        public ?int $statusCode,
        public ?CheckError $error = null,
        public ?CarbonImmutable $tlsExpiresAt = null,
    ) {
        if (($status === MonitorStatus::Up && $error !== null)
            || ($status === MonitorStatus::Down && $error === null)
            || $status === MonitorStatus::Unknown
            || ($latencyMs !== null && (!is_finite($latencyMs) || $latencyMs < 0))
            || ($statusCode !== null && ($statusCode < 100 || $statusCode > 599))) {
            throw new InvalidArgumentException('Check outcome fields are inconsistent.');
        }
    }

    /** @return string|null Generic safe diagnostic, absent on successful checks. */
    public function errorMessage(): ?string
    {
        return $this->error?->message();
    }
}
