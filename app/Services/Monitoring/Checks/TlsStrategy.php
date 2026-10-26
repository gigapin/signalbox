<?php

declare(strict_types=1);

/** Evaluate verified certificate validity and a deterministic pre-expiry failure window. */

namespace App\Services\Monitoring\Checks;

use App\Data\Monitoring\Checks\CheckInput;
use App\Data\Monitoring\Checks\CheckOutcome;
use App\Data\Monitoring\TlsMonitorConfiguration;
use App\Enums\Monitoring\CheckError;
use App\Enums\Monitoring\MonitorStatus;
use Carbon\CarbonImmutable;
use InvalidArgumentException;

/** Only verified handshakes supply an expiry; this strategy never performs network I/O. */
final class TlsStrategy
{
    /**
     * @param CheckInput $input Admitted TLS configuration and scalar identity.
     * @param CarbonImmutable $expiresAt Verified leaf certificate expiry.
     * @param CarbonImmutable $observedAt Actual UTC completion instant.
     * @param float $latencyMs Measured DNS/TCP/TLS acquisition milliseconds.
     * @return CheckOutcome Normalized result containing the verified certificate expiry only.
     * @throws InvalidArgumentException When the configuration is not TLS.
     */
    public function evaluate(CheckInput $input, CarbonImmutable $expiresAt, CarbonImmutable $observedAt, float $latencyMs): CheckOutcome
    {
        $configuration = $input->configuration;

        if (!$configuration instanceof TlsMonitorConfiguration) {
            throw new InvalidArgumentException('TLS evaluation requires a TLS configuration.');
        }

        $error = null;

        if ($expiresAt->lessThanOrEqualTo($observedAt)) {
            $error = CheckError::Tls;
        } elseif ($configuration->expiryWarningDays !== null
            && $expiresAt->lessThanOrEqualTo($observedAt->addSeconds($configuration->expiryWarningDays * 86400))) {
            $error = CheckError::TlsExpiring;
        }

        return new CheckOutcome($input->context, $error === null ? MonitorStatus::Up : MonitorStatus::Down, $observedAt, $latencyMs, null, $error, $expiresAt->utc());
    }
}
