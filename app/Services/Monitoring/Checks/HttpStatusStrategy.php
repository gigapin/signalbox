<?php

declare(strict_types=1);

/** Evaluate the configured status without interpreting a successful response body. */

namespace App\Services\Monitoring\Checks;

use App\Contracts\Monitoring\HttpResponseStrategy;
use App\Data\Monitoring\Checks\CheckInput;
use App\Data\Monitoring\Checks\CheckOutcome;
use App\Data\Monitoring\Checks\HttpObservation;
use App\Data\Monitoring\HttpMonitorConfiguration;
use App\Enums\Monitoring\CheckError;
use App\Enums\Monitoring\MonitorStatus;
use InvalidArgumentException;

/** A configured non-2xx status is a valid success when it matches exactly. */
final class HttpStatusStrategy implements HttpResponseStrategy
{
    /**
     * @param CheckInput $input HTTP configuration and correlation identity.
     * @param HttpObservation $observation Complete bounded final response.
     * @return CheckOutcome Exact status comparison, without response content.
     * @throws InvalidArgumentException When the configuration is not HTTP.
     */
    public function evaluate(CheckInput $input, #[\SensitiveParameter] HttpObservation $observation): CheckOutcome
    {
        if (!$input->configuration instanceof HttpMonitorConfiguration) {
            throw new InvalidArgumentException('The HTTP strategy requires HTTP configuration.');
        }

        $matched = $observation->statusCode === $input->configuration->expectedStatus;

        return new CheckOutcome(
            $input->context,
            $matched ? MonitorStatus::Up : MonitorStatus::Down,
            $observation->observedAt,
            $observation->latencyMs,
            $observation->statusCode,
            $matched ? null : CheckError::UnexpectedStatus,
        );
    }
}
